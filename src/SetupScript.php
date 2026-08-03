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
        $io->write("<info>1. Baixando Laravel oficial</info>");
        $io->write("<info>========================================</info>");

        $tempFolder = $projectPath . '/_laravel_temp';

        // 1. Baixa a estrutura oficial do Laravel na pasta temporária
        self::run("composer create-project laravel/laravel \"{$tempFolder}\" --prefer-dist --no-scripts --no-install");

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
        $io->write("<info>2. Aplicando customizações USPDev para projeto: {$projectName}</info>");
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
        $io->write("<info>3. Instalando pacotes e update</info>");
        $io->write("<info>========================================</info>");

        // 7. Instala o framework Laravel e requer pacotes USPDev
        self::run('composer update');
        self::run('composer require uspdev/laravel-usp-theme');
        self::run('composer require uspdev/senhaunica-socialite');

        # devs
        self::run('composer req uspdev/simple-crud-generator --dev');
        self::run('composer req laravel/dusk --dev');
        self::run('php artisan dusk:install');
        self::run('php artisan dusk:chrome-driver');


        // 8. Publica assets, gera chaves e roda migrações
        if (file_exists($projectPath . '/artisan')) {
            self::run('php artisan vendor:publish --provider="Uspdev\UspTheme\ServiceProvider" --tag=config');
            self::run('php artisan vendor:publish --provider="Uspdev\SenhaunicaSocialite\SenhaunicaServiceProvider" --tag="migrations"');
            self::run('php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"');
            self::run('php artisan key:generate');
        }
        self::addPublishAssetsToComposer($projectPath);

        // 10. Limpa o instalador do starter
        @unlink($projectPath . '/src/SetupScript.php');
        @rmdir($projectPath . '/src');

        $io->write("<info>Projeto {$projectName} criado e configurado com sucesso!</info>");
    }

    private static function updateGitignore(string $path)
    {
        $gitignoreFile = $path . '/.gitignore';
        if (file_exists($gitignoreFile)) {
            $content = file_get_contents($gitignoreFile);

            if (!str_contains($content, 'composer.lock')) {
                $extraContent = "\n# uspdev-theme folder\npublic/vendor\n";
                file_put_contents($gitignoreFile, $extraContent, FILE_APPEND);
            }
        }
    }

    private static function setupEnvironmentFiles(string $path, string $projectName)
    {
        $stubPath = $path . '/stubs/env.stub';
        if (!file_exists($stubPath)) return;

        // Lê o conteúdo das variáveis customizadas (USPDev)
        $customEnvContent = file_get_contents($stubPath);
        $customEnvContent = str_replace('MEU_APP', $projectName, $customEnvContent);

        // Prepara o bloco a ser anexado
        $appendContent = "\n\n# ========================================\n";
        $appendContent .= "# Configurações Adicionais USPDev\n";
        $appendContent .= "# ========================================\n";
        $appendContent .= $customEnvContent;

        // 1. Anexa no .env.example (trazido pelo Laravel)
        $envExamplePath = $path . '/.env.example';
        if (file_exists($envExamplePath)) {
            $currentExample = file_get_contents($envExamplePath);
            // Anexa apenas se ainda não tiver as variáveis USPDev
            if (!str_contains($currentExample, 'USPDev')) {
                file_put_contents($envExamplePath, $appendContent, FILE_APPEND);
            }
        }

        // 2. Se o .env não existir na raiz, copia a partir do .env.example já atualizado
        $envPath = $path . '/.env';
        if (!file_exists($envPath) && file_exists($envExamplePath)) {
            copy($envExamplePath, $envPath);
        }

        // 3. Aplica as alterações exclusivas do .env
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);

            // Substitui apenas no .env a linha APP_URL por http://127.0.0.1:8000
            $envContent = preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://127.0.0.1:8000', $envContent);

            // Garante que o bloco USPDev seja anexado caso o .env já existia antes da cópia
            if (!str_contains($envContent, 'USPDev')) {
                $envContent .= $appendContent;
            }

            file_put_contents($envPath, $envContent);
        }
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

    private static function addPublishAssetsToComposer(string $path)
    {
        $composerPath = $path . '/composer.json';

        if (!file_exists($composerPath)) {
            return;
        }

        $jsonContent = file_get_contents($composerPath);
        $composerData = json_decode($jsonContent, true);

        if (!is_array($composerData)) {
            return;
        }

        $command = '@php artisan vendor:publish --provider="Uspdev\\UspTheme\\ServiceProvider" --tag=assets --force';

        // Garante que a estrutura de arrays exista
        if (!isset($composerData['scripts'])) {
            $composerData['scripts'] = [];
        }

        if (!isset($composerData['scripts']['post-autoload-dump'])) {
            $composerData['scripts']['post-autoload-dump'] = [];
        }

        // Se o post-autoload-dump for apenas uma string simples no JSON, converte para array
        if (is_string($composerData['scripts']['post-autoload-dump'])) {
            $composerData['scripts']['post-autoload-dump'] = [$composerData['scripts']['post-autoload-dump']];
        }

        // Adiciona o comando apenas se ele ainda não existir no array
        if (!in_array($command, $composerData['scripts']['post-autoload-dump'])) {
            $composerData['scripts']['post-autoload-dump'][] = $command;

            // Salva o composer.json formatado com recuo limpo
            $updatedJson = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($composerPath, $updatedJson);
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

    private static function run(string $cmd): void
    {
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Falhou: $cmd\n" . implode("\n", $output));
        }
    }


}