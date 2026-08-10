<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Security;

use InvalidArgumentException;

final readonly class SensitiveActionContext
{
    public string $action;

    public ?string $subject;

    public function __construct(string $action, ?string $subject = null)
    {
        $action = trim($action);
        $subject = is_string($subject) ? trim($subject) : null;

        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{2,119}\z/D', $action)) {
            throw new InvalidArgumentException(
                'A sensitive-action name must be a 3-120 character lowercase identifier.',
            );
        }

        if ($subject === '') {
            $subject = null;
        }

        if ($subject !== null
            && (mb_strlen($subject) > 255 || preg_match('/[\x00-\x1F\x7F]/', $subject))) {
            throw new InvalidArgumentException(
                'A sensitive-action subject must not exceed 255 characters or contain control characters.',
            );
        }

        $this->action = $action;
        $this->subject = $subject;
    }

    public function canonical(): string
    {
        return $this->action."\0".($this->subject ?? '');
    }
}
