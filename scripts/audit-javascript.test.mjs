import { expect, test } from 'bun:test';
import { checkAudit } from './audit-javascript.mjs';

const policy = [{ name: 'example', version: '1.0.0', id: 'GHSA-test-test-test', expires: '2026-11-16' }];
const now = new Date('2026-10-17T00:00:00Z');
const lock = () => ({
    workspaces: { '': { dependencies: { runtime: '1' }, devDependencies: { nuxt: '4' } } },
    packages: {
        runtime: ['runtime@1.0.0', '', {}],
        nuxt: ['nuxt@4.0.0', '', { dependencies: { example: '1' } }],
        example: ['example@1.0.0', '', {}],
    },
});
const audit = { example: [{ url: 'https://github.com/advisories/GHSA-test-test-test', severity: 'high' }] };

test('accepts only the exact approved development advisory and version', () => {
    expect(checkAudit(lock(), audit, now, policy).errors).toEqual([]);
    expect(checkAudit(lock(), audit, now, policy).accepted).toHaveLength(1);
});

test('blocks the same advisory when reachable from production dependencies', () => {
    const fixture = lock();
    fixture.packages.runtime[2].dependencies = { example: '1' };
    expect(checkAudit(fixture, audit, now, policy).errors.length).toBeGreaterThan(0);
});

test('blocks version drift, non-Nuxt paths, expiry, and new advisories', () => {
    const changed = lock();
    changed.packages.example[0] = 'example@1.0.1';
    expect(checkAudit(changed, audit, now, policy).errors.length).toBeGreaterThan(0);
    const outsideNuxt = lock();
    outsideNuxt.packages.nuxt[2] = {};
    expect(checkAudit(outsideNuxt, audit, now, policy).errors.length).toBeGreaterThan(0);
    expect(checkAudit(lock(), audit, new Date('2026-11-16T00:00:00Z'), policy).errors.length).toBeGreaterThan(0);
    expect(checkAudit(lock(), { other: audit.example }, now, policy).errors.length).toBeGreaterThan(0);
});

test('requires removing exceptions once upstream advisories disappear', () => {
    expect(checkAudit(lock(), {}, now, policy).errors.length).toBeGreaterThan(0);
});

test('does not allow a vulnerable nested version or optional production edge', () => {
    const nested = lock();
    nested.packages['nested/example'] = ['example@0.9.0', '', {}];
    expect(checkAudit(nested, audit, now, policy).errors.length).toBeGreaterThan(0);
    const optional = lock();
    optional.packages.runtime[2].optionalDependencies = { example: '1' };
    expect(checkAudit(optional, audit, now, policy).errors.length).toBeGreaterThan(0);
});
