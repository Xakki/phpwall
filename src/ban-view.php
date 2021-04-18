<?php

declare(strict_types=1);

use Xakki\PHPWall\PHPWall;

/**
 * @var PHPWall $this
 */
?>
<!doctype html>
<html lang="en">
<head>
    <title>PHPWall <?= PHPWall::VERSION ?> - <?= $this->locale('Attention'); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css"
          integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
    <style>
        /*
 * Globals
 */

        /* Links */
        a,
        a:focus,
        a:hover {
            color: #fff;
        }

        /* Custom default button */
        .btn-secondary,
        .btn-secondary:hover,
        .btn-secondary:focus {
            color: #333;
            text-shadow: none; /* Prevent inheritance from `body` */
            background-color: #fff;
            border: .05rem solid #fff;
        }

        /*
         * Base structure
         */

        html,
        body {
            height: 100%;
            background-color: #333;
        }

        body {
            display: -ms-flexbox;
            display: flex;
            color: #fff;
            text-shadow: 0 .05rem .1rem rgba(0, 0, 0, .5);
            box-shadow: inset 0 0 5rem rgba(0, 0, 0, .5);
        }

        .cover-container {
            max-width: 42em;
        }

        /*
         * Header
         */
        .masthead {
            margin-bottom: 2rem;
        }

        .masthead-brand {
            margin-bottom: 0;
        }

        .nav-masthead .nav-link {
            padding: .25rem 0;
            font-weight: 700;
            color: rgba(255, 255, 255, .5);
            background-color: transparent;
            border-bottom: .25rem solid transparent;
        }

        .nav-masthead .nav-link:hover,
        .nav-masthead .nav-link:focus {
            border-bottom-color: rgba(255, 255, 255, .25);
        }

        .nav-masthead .nav-link + .nav-link {
            margin-left: 1rem;
        }

        .nav-masthead .active {
            color: #fff;
            border-bottom-color: #fff;
        }

        @media (min-width: 48em) {
            .masthead-brand {
                float: left;
            }

            .nav-masthead {
                float: right;
            }
        }

        /*
         * Cover
         */
        .cover {
            padding: 0 1.5rem;
        }

        .cover .btn-lg {
            padding: .75rem 1.25rem;
            font-weight: 700;
        }

        /*
         * Footer
         */
        .mastfoot {
            color: rgba(255, 255, 255, .5);
        }

    </style>
    <?php if (!empty($this->getGoogleCaptchaSiteKey())) : ?>
        <script src='https://www.google.com/recaptcha/api.js'></script>
    <?php endif; ?>
</head>

<body class="text-center">

<div class="cover-container d-flex w-100 h-100 p-3 mx-auto flex-column">
    <header class="masthead mb-auto">
        <div class="inner">
            <h3 class="masthead-brand">PHPWall</h3>
            <nav class="nav nav-masthead justify-content-center">
                <a class="nav-link" href="/"><?= $this->locale('Home'); ?></a>
            </nav>
        </div>
    </header>

    <main role="main" class="inner cover">
        <h1 class="cover-heading"><?= $this->locale('Attention'); ?>!</h1>
        <p class="lead"><?= $this->locale('Your IP [{$0}] has been blocked for suspicious activity.', [$_SERVER['REMOTE_ADDR'] ?? '']); ?></p>
        <p><?= $this->locale('If you want to remove the lock, then go check the captcha.'); ?></p>

        <?php if (!empty($this->getGoogleCaptchaSiteKey())) : ?>
            <form method="POST">
                <input type="hidden" name="<?=PHPWall::POST_WALL_NAME?>" value="please"/>
                <div class="g-recaptcha" data-sitekey="<?= $this->getGoogleCaptchaSiteKey() ?>" style="display: inline-block;"></div>
                <div><input type="submit" value="<?= $this->locale('Unbun'); ?>" class="btn btn-lg btn-secondary"></div>
            </form>
        <?php endif; ?>

        <?php if ($this->getErrorMessage()) :
            ?><br/>
            <p class="alert alert-danger"><?= $this->locale($this->getErrorMessage()) ?></p>
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
