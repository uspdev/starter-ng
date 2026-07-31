<?php

namespace Uspdev\Starter;

use Composer\Script\Event;

class SetupScript
{
    public static function postCreateProject(Event $event)
    {
        $io = $event->getIO();
        
        // Fixa o caminho absoluto real da raiz do novo projeto
        $projectPath = realpath(getcwd());
        $projectName = basename($projectPath);

        $io->write("<info>========================================</info>");
        $io->write("<info>1. Baixando e mesclando Laravel oficial...</info>");
        $io->write("<info>========================================</info>");

        $tempFolder = $projectPath . '/_laravel_temp';

        // 1. Baixa o Laravel oficial na pasta temporaria sem rodar scripts/install
        exec("composer create-project laravel/laravel \"{$tempFolder}\" --prefer-dist --no-scripts --no-install");

        // GARANTIA: Retorna para o diretorio raiz caso o composer tenha mudado o CWD
        chdir($projectPath);


        // 2. Mover arquivos do Laravel temporario para a raiz
        if (is_dir($tempFolder)) {
            $items = scandir($tempFolder);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;

                $source = $tempFolder . '/' . $item;
                $target = $projectPath . '/' . $item;

                // Força a cópia do .gitignore e do composer.json oficiais do Laravel!
                if ($item === '.gitignore' || $item === 'composer.json') {
                    copy($source, $target);
                    continue;
                }

                // Para os demais arquivos/pastas, move se não existir
                if (!file_exists($target)) {
                    rename($source, $target);
                }
            }
            self::recursiveRmdir($tempFolder);
        }


        $io->write("<info>========================================</info>");
        $io->write("<info>2. Aplicando customizacoes USPDev: {$projectName}...</info>");
        $io->write("<info>========================================</info>");

        // 3. Anexa as regras ao .gitignore (que acabou de ser trazido do Laravel)
        self::updateGitignore($projectPath);

        // 4. Configura .env e .env.example usando o stub
        self::setupEnvironmentFiles($projectPath, $projectName);

        // 5. Aplica Stubs (Views, Controllers, Docker) e ANEXA rotas ao routes/web.php
        self::applyStubs($projectPath, $projectName);

        // 6. Atualiza a Model User.php com as Traits do SenhaUnica
        self::updateUserModel($projectPath);

        $io->write("<info>========================================</info>");
        $io->write("<info>3. Instalando pacotes e dependencias...</info>");
        $io->write("<info>========================================</info>");

        // 7. Requer pacotes adicionais do USPDev
        exec('composer update');
        exec('composer require uspdev/laravel-usp-theme uspdev/senhaunica-socialite');

        // 8. Publica assets e roda migracoes se o artisan existir
        if (file_exists($projectPath . '/artisan')) {
            exec('php artisan vendor:publish --provider="Uspdev\UspTheme\ServiceProvider" --tag=config');
            exec('php artisan vendor:publish --provider="Uspdev\SenhaunicaSocialite\SenhaunicaServiceProvider" --tag="migrations"');
            exec('php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"');
            exec('php artisan migrate');
            exec('php artisan key:generate');
        }

        // 9. Limpa arquivos do instalador do starter
        @unlink($projectPath . '/src/SetupScript.php');
        @rmdir($projectPath . '/src');

        $io->write("<info>🚀 Projeto {$projectName} criado e configurado com sucesso!</info>");
    }

    private static function updateGitignore(string $path)
    {
        $gitignoreFile = $path . '/.gitignore';
        if (file_exists($gitignoreFile)) {
            $content = file_get_contents($gitignoreFile);

            // Anexa as linhas apenas se ainda nao existirem
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

        // Mapeamento dos stubs que SOBRESCREVEM completamente os arquivos
        $stubMap = [
            'Dockerfile.stub'          => $path . '/Dockerfile',
            'docker-compose.yml.stub'  => $path . '/docker-compose.yml',
            'IndexController.php.stub' => $path . '/app/Http/Controllers/IndexController.php',
            'layout.blade.php.stub'    => $path . '/resources/views/layout.blade.php',
            'index.blade.php.stub'     => $path . '/resources/views/index.blade.php',
            'web_routes.php.stub'      => $path . '/routes/web.php', // <--- Sobrescreve o web.php do Laravel
        ];

        foreach ($stubMap as $stubFile => $destination) {
            $source = $path . '/stubs/' . $stubFile;
            if (file_exists($source)) {
                $content = file_get_contents($source);
                $content = str_replace('MEU_APP', $projectName, $content);
                file_put_contents($destination, $content); // Sobrescreve o conteúdo original
            }
        }

        // Limpa a pasta stubs
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