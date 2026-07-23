<?php

namespace App\Enums;

enum AuthCredentialType: string
{
    case SESSION = 'session';
    case TOKEN = 'token';
}
