import { fileURLToPath } from 'node:url';

// Maintainer-approved, development-tool-only exceptions. See docs/security-audit.md.
export const exceptions = [
    { name: 'deepmerge', version: '4.3.1', id: 'GHSA-gcqx-w48m-w2h4', severity: 'low', expires: '2026-11-16' },
];

export function checkAudit(lock, advisories, now = new Date(), policy = exceptions) {
    const records = new Map();
    for (const record of Object.values(lock.packages)) {
        const match = record[0].match(/^(.+)@([^@]+)$/);
        if (!match) continue;
        const entries = records.get(match[1]) ?? [];
        entries.push({ version: match[2], metadata: record[2] ?? {} });
        records.set(match[1], entries);
    }

    // Conservative: inspect every locked version and include optional/peer edges.
    const reachable = (roots) => {
        const visited = new Set();
        const queue = [...roots];
        while (queue.length) {
            const name = queue.pop();
            if (visited.has(name)) continue;
            visited.add(name);
            for (const { metadata } of records.get(name) ?? []) {
                queue.push(...Object.keys({
                    ...metadata.dependencies,
                    ...metadata.optionalDependencies,
                    ...metadata.peerDependencies,
                }));
            }
        }
        return visited;
    };

    const production = reachable(Object.values(lock.workspaces).flatMap(
        (workspace) => Object.keys({ ...workspace.dependencies, ...workspace.optionalDependencies }),
    ));
    const tooling = reachable(['nuxt']);
    const errors = [];
    const accepted = [];
    const seen = new Set();

    for (const [name, findings] of Object.entries(advisories)) {
        if (!Array.isArray(findings)) throw new Error('Unexpected Bun audit response.');
        for (const finding of findings) {
            const id = finding.url?.match(/\/advisories\/(GHSA-[\w-]+)$/)?.[1];
            const exception = policy.find((entry) => entry.name === name && entry.id === id);
            const versions = records.get(name) ?? [];
            if (!exception || production.has(name) || !tooling.has(name)
                || versions.length === 0 || versions.some((entry) => entry.version !== exception.version)
                || (exception.severity && exception.severity !== finding.severity)
                || now >= new Date(`${exception.expires}T00:00:00Z`)) {
                errors.push(`${name}: ${id ?? finding.url ?? 'unknown advisory'} (${finding.severity})`);
                continue;
            }
            seen.add(id);
            accepted.push(`${name}@${exception.version}: ${id}; expires ${exception.expires}`);
        }
    }

    for (const exception of policy) {
        if (!seen.has(exception.id)) {
            errors.push(`Remove or re-review stale/invalid exception: ${exception.id}`);
        }
    }

    return { errors, accepted };
}

if (import.meta.main) {
    try {
        const root = fileURLToPath(new URL('../', import.meta.url));
        const result = Bun.spawnSync(['bun', 'audit', '--json'], { cwd: root, stdout: 'pipe', stderr: 'pipe' });
        const output = result.stdout.toString().trim();
        if (![0, 1].includes(result.exitCode) || !output) {
            throw new Error(`Bun audit failed: ${result.stderr.toString()}`);
        }
        const advisories = JSON.parse(output);
        if (!advisories || Array.isArray(advisories) || typeof advisories !== 'object') {
            throw new Error('Unexpected Bun audit response.');
        }
        if (result.exitCode !== 0 && Object.keys(advisories).length === 0) {
            throw new Error('Bun audit failed without an advisory report.');
        }
        const lock = Bun.JSONC.parse(await Bun.file(new URL('../bun.lock', import.meta.url)).text());
        const { errors, accepted } = checkAudit(lock, advisories);
        for (const message of accepted) console.warn(`APPROVED DEVELOPMENT EXCEPTION: ${message}`);
        if (errors.length) throw new Error(errors.join('\n'));
        console.log('JavaScript audit policy passed; all non-exempt advisories remain blocking.');
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
