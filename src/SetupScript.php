<?php

namespace Uspdev\Starter;

use Composer\Script\Event;

class SetupScript
{
    public static function postCreateProject(Event $event)
    {
        $io = $event->getIO();
        
        // Caminho absoluto real da raiz do projeto
        $projectPath = realpath(getcwd());
        $projectName = basename($projectPath);

        $io->write("<info>========================================</info>");
        $io->write("<info>1. Baixando e mesclando Laravel oficial...</info>");
        $io->write("<info>========================================</info>");

        $tempFolder = $projectPath . '/_laravel_temp';

        // 1. Baixa a estrutura oficial do Laravel na pasta temporária
        exec("composer create-project laravel/laravel \"{$tempFolder}\" --prefer-dist --no-scripts --no-install");

        // GARANTIA: Retorna para o diretório raiz
        chdir($projectPath);

        // 2. Mover arquivos do Laravel temporário para a raiz
        if (is_dir($tempFolder)) {
            $items = scandir($tempFolder);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;

                $source = $tempFolder . '/' . $item;
                $target = $projectPath . '/' . $item;

                // Sobrescreve o .gitignore e o composer.json do starter pelos oficiais do Laravel!
                if ($item === '.gitignore' || $item === 'composer.json') {
                    copy($source, $target);
                    continue;
                }

                // Move demais pastas e arquivos (app, config, routes, public, etc)
                if (!file_exists($target)) {
                    rename($source, $target);
                }
            }
            self::recursiveRmdir($tempFolder);
        }

        $io->write("<info>========================================</info>");
        $io->write("<info>2. Aplicando customizações USPDev: {$projectName}...</info>");
        $io->write("<info>========================================</info>");

        // 3. Anexa regras USPDev no .gitignore oficial
        self::updateGitignore($projectPath);

        // 4. Configura .env e .env.example usando o stub
        self::setupEnvironmentFiles($projectPath, $projectName);

        // 5. Aplica Stubs (Views, Controller, Docker) e sobrescreve routes/web.php
        self::applyStubs($projectPath, $projectName);

        // 6. Atualiza a Model User.php com as Traits do SenhaÚnica
        self::updateUserModel($projectPath);

        $io->write("<info>========================================</info>");
        $io->write("<info>3. Instalando pacotes e gerando autoloader...</info>");
        $io->write("<info>========================================</info>");

        // 7. Instala o framework Laravel e requer pacotes USPDev
        exec('composer update');
        exec('composer require uspdev/laravel-usp-theme uspdev/senhaunica-socialite');

        // 8. Publica assets, gera chaves e roda migrações
        if (file_exists($projectPath . '/artisan')) {
            exec('php artisan vendor:publish --provider="Uspdev\UspTheme\ServiceProvider" --tag=config');
            exec('php artisan vendor:publish --provider="Uspdev\SenhaunicaSocialite\SenhaunicaServiceProvider" --tag="migrations"');
            exec('php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"');
            exec('php artisan migrate');
            exec('php artisan key:generate');
        }

        // 9. DUMP AUTOLOAD para mapear todas as novas classes e controllers gerados!
        $io->write("<info>Otimizando e reconstruindo autoloader do Composer...</info>");
        exec('composer dump-autoload -o');

        // 10. Limpa o instalador do starter
        @unlink($projectPath . '/src/SetupScript.php');
        @rmdir($projectPath . '/src');

        $io->write("<info>🚀 Projeto {$projectName} criado e configurado com sucesso!</info>");
    }

    private static function updateGitignore(string $path)
    {
        $gitignoreFile = $path . '/.gitignore';
        if (file_exists($gitignoreFile)) {
            $content = file_get_contents($gitignoreFile);

            if (!str_contains($content, 'composer.lock')) {
                $extraContent = "\n# Regras USPDev\ncomposer.lock\npublic/vendor\n";
                file_put_contents($gitignoreFile, $extraContent, FILE_APPEND);
            }
        }
    }

    private static function setupEnvironmentFiles(string $path, string $projectName)
    {
        $stubPath = $path . '/stubs/env.stub';
        if (!file_exists($stubPath)) return;

        $envContent = file_get_contents($stubPath);
        $envExampleContent = str_replace('MEU_APP', $projectName, $envContent);
        
        file_put_contents($path . '/.env.example', $envExampleContent);

        $envLocalContent = str_replace("APP_URL=http://{$projectName}", "APP_URL=http://127.0.0.1:8000", $envExampleContent);
        file_put_contents($path . '/.env', $envLocalContent);
    }

    private static function updateUserModel(string $path)
    {
        $userModelPath = $path . '/app/Models/User.php';
        if (!file_exists($userModelPath)) return;

        $content = file_get_contents($userModelPath);

        if (!str_contains($content, 'HasSenhaunica')) {
            $traits = "    use \\Spatie\\Permission\\Traits\\HasRoles;\n    use \\Uspdev\\SenhaunicaSocialite\\Traits\\HasSenhaunica;\n\n    protected \$guard_name = 'senhaunica';\n";
            $content = preg_replace('/class User extends Authenticatable\s*\{/', "class User extends Authenticatable\n{\n{$traits}", $content);
            file_put_contents($userModelPath, $content);
        }
    }

    private static function applyStubs(string $path, string $projectName)
    {
        @mkdir($path . '/resources/views', 0755, true);
        @mkdir($path . '/app/Http/Controllers', 0755, true);
        @mkdir($path . '/routes', 0755, true);

        // Stubs que SOBRESCREVEM os arquivos de destino
        $stubMap = [
            'Dockerfile.stub'          => $path . '/Dockerfile',
            'docker-compose.yml.stub'  => $path . '/docker-compose.yml',
            'IndexController.php.stub' => $path . '/app/Http/Controllers/IndexController.php',
            'layout.blade.php.stub'    => $path . '/resources/views/layout.blade.php',
            'index.blade.php.stub'     => $path . '/resources/views/index.blade.php',
            'web_routes.php.stub'      => $path . '/routes/web.php',
        ];

        foreach ($stubMap as $stubFile => $destination) {
            $source = $path . '/stubs/' . $stubFile;
            if (file_exists($source)) {
                $content = file_get_contents($source);
                $content = str_replace('MEU_APP', $projectName, $content);
                file_put_contents($destination, $content);
            }
        }

        // Limpa a pasta stubs do projeto final
        array_map('unlink', glob("$path/stubs/*.*"));
        @rmdir($path . '/stubs');
    }

    private static function recursiveRmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        self::recursiveRmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}