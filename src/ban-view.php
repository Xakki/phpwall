<?php

use Xakki\PHPWall\PHPWall;

/**
 * This is the default ban page template for PHPWall.
 * It informs the user about the block and provides a reCAPTCHA to get unbanned.
 *
 * @var PHPWall $phpWall The instance of the main PHPWall class.
 * @see PHPWall::wallAlarmAction()
 */

?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($phpWall->getLang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <title>PHPWall <?php echo PHPWall::VERSION ?> - <?php echo $phpWall->locale('Attention') ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css"
          integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
    <style>
        /* Globals */
        a, a:focus, a:hover { color: #fff; }
        .btn-secondary, .btn-secondary:hover, .btn-secondary:focus {
            color: #333; text-shadow: none; background-color: #fff; border: .05rem solid #fff;
        }
        html, body { height: 100%; background-color: #333; }
        body { display: -ms-flexbox; display: flex; color: #fff; text-shadow: 0 .05rem .1rem rgba(0, 0, 0, .5); box-shadow: inset 0 0 5rem rgba(0, 0, 0, .5); }
        .cover-container { max-width: 42em; }
        .masthead { margin-bottom: 2rem; }
        .masthead-brand { margin-bottom: 0; }
        .nav-masthead .nav-link { padding: .25rem 0; font-weight: 700; color: rgba(255, 255, 255, .5); background-color: transparent; border-bottom: .25rem solid transparent; }
        .nav-masthead .nav-link:hover, .nav-masthead .nav-link:focus { border-bottom-color: rgba(255, 255, 255, .25); }
        .nav-masthead .nav-link + .nav-link { margin-left: 1rem; }
        .nav-masthead .active { color: #fff; border-bottom-color: #fff; }
        @media (min-width: 48em) {
            .masthead-brand { float: left; }
            .nav-masthead { float: right; }
        }
        .cover { padding: 0 1.5rem; }
        .cover .btn-lg { padding: .75rem 1.25rem; font-weight: 700; }
        .mastfoot { color: rgba(255, 255, 255, .5); }
    </style>
    <?php if ($phpWall->getGoogleCaptchaSiteKey()) : ?>
        <script src='https://www.google.com/recaptcha/api.js' async defer></script>
    <?php endif; ?>
</head>

<body class="text-center">

<div class="cover-container d-flex w-100 h-100 p-3 mx-auto flex-column">
    <header class="masthead mb-auto">
        <div class="inner">
            <h3 class="masthead-brand">PHPWall</h3>
            <nav class="nav nav-masthead justify-content-center">
                <a class="nav-link" href="/"><?php echo $phpWall->locale('Home') ?></a>
            </nav>
        </div>
    </header>

    <main role="main" class="inner cover">
        <h1 class="cover-heading"><?php echo $phpWall->locale('Attention') ?>!</h1>
        <p class="lead"><?php echo $phpWall->locale('Your IP [{$0}] has been blocked for suspicious activity.', [$phpWall->getUserIp()]) ?></p>
        <p><?php echo $phpWall->locale('If you want to remove the lock, please complete the check.') ?></p>

        <?php if ($phpWall->getGoogleCaptchaSiteKey()) : ?>
            <form method="POST" action="">
                <input type="hidden" name="<?php echo PHPWall::POST_WALL_NAME ?>" value="please"/>
                <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($phpWall->getGoogleCaptchaSiteKey(), ENT_QUOTES, 'UTF-8') ?>" style="display: inline-block;"></div>
                <br>
                <button type="submit" class="btn btn-lg btn-secondary mt-2"><?php echo $phpWall->locale('Unblock') ?></button>
            </form>
        <?php endif; ?>

        <?php if ($phpWall->getErrorMessage()) : ?>
            <br/>
            <p class="alert alert-danger"><?php echo $phpWall->locale($phpWall->getErrorMessage()) ?></p>
        <?php endif; ?>
    </main>

    <footer class="mastfoot mt-auto">
        <div class="inner">
            <p>Web protection by <a href="https://github.com/xakki/phpwall">PHPWall</a>.</p>
        </div>
    </footer>
</div>

</body>
</html>
