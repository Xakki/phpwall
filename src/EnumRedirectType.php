<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

/**
 * Defines the types of redirection actions to be taken when a request is blocked.
 */
enum EnumRedirectType
{
    /**
     * Redirect to the informational ban page where the user can solve a CAPTCHA to get unbanned.
     * @see PHPWall::wallAlarmAction()
     */
    case REDIRECT_TYPE_INFO;

    /**
     * Redirect the request to the user's own IP address.
     * This is an aggressive blocking method that can cause the user's browser to hang.
     * It is effective against simple bots.
     * @see PHPWall::wallAlarmAction()
     */
    case REDIRECT_TYPE_SELF;
}
