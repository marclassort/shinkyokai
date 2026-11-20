<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Étanchéité des tests : une variable d'environnement définie par le shell ou l'IDE (ex. DATABASE_URL de la base locale)
// l'emporterait sur .env.test. On force donc ici une base SQLite en mémoire et un transport d'e-mails nul.
foreach (["DATABASE_URL" => "sqlite:///:memory:", "MAILER_DSN" => "null://null"] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv("$name=$value");
}
