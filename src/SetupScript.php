<?php

namespace Uspdev\Starter;

use Composer\Script\Event;

class SetupScript
{
    public static function postCreateProject(Event $event)
    {
        $io = $event->getIO();
        $projectPath = getcwd();
        $projectName = basename($projectPath);

        $io->write("<info>Configurando o projeto USPDev: {$projectName}...</info>");

        // 1. Configura .env e .env.example usando o stub
        self::setupEnvironmentFiles($projectPath, $projectName);

        // 2. Atualiza .gitignore
        self::updateGitignore($projectPath);

        // 3. Atualiza User.php PRIMEIRO (Requisito estrito para SenhaÚnica)
        self::updateUserModel($projectPath);

        // 4. Copia e aplica todos os Stubs (Infra, Views, Controller, Rotas e o README da raiz)
        self::applyStubs($projectPath, $projectName);

        // 5. Publica assets da lib de tema
        exec('php artisan vendor:publish --provider="Uspdev\UspTheme\ServiceProvider" --tag=config');

        // 6. Instala o senhaunica-socialite APÓS a Model User estar com as traits
        $io->write("<info>Instalando uspdev/senhaunica-socialite...</info>");
        exec('composer require uspdev/senhaunica-socialite');

        // 7. Publica migrações e executa
        exec('php artisan vendor:publish --provider="Uspdev\SenhaunicaSocialite\SenhaunicaServiceProvider" --tag="migrations"');
        exec('php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"');
        exec('php artisan migrate');

        // 8. Configura Dusk
        exec('php artisan dusk:install');
        exec('php artisan dusk:chrome-driver');

        // 9. Gerar chave da aplicação
        exec('php artisan key:generate');

        $io->write("<info>Projeto {$projectName} configurado com sucesso!</info>");
    }

    private static function updateGitignore(string $path)
    {
        $file = $path . '/.gitignore';
        if (file_exists($file)) {
            file_put_contents($file, "\npublic/vendor\n", FILE_APPEND);
        }
    }

    private static function updateUserModel(string $path)
    {
        $userModelPath = $path . '/app/Models/User.php';
        if (!file_exists($userModelPath)) return;

        $content = file_get_contents($userModelPath);

        $traits = "    use \\Spatie\\Permission\\Traits\\HasRoles;\n    use \\Uspdev\\SenhaunicaSocialite\\Traits\\HasSenhaunica;\n\n    protected \$guard_name = 'senhaunica';\n";
        
        $content = preg_replace('/class User extends Authenticatable\s*\{/', "class User extends Authenticatable\n{\n{$traits}", $content);

        file_put_contents($userModelPath, $content);
    }

    private static function applyStubs(string $path, string $projectName)
    {
        // Garante a existência dos diretórios de destino
        @mkdir($path . '/resources/views', 0755, true);
        @mkdir($path . '/app/Http/Controllers', 0755, true);

        // Mapeamento dos stubs para suas localizações finais
        $stubMap = [
            'Dockerfile.stub'          => $path . '/Dockerfile',
            'docker-compose.yml.stub'  => $path . '/docker-compose.yml',
            'IndexController.php.stub' => $path . '/app/Http/Controllers/IndexController.php',
            'layout.blade.php.stub'    => $path . '/resources/views/layout.blade.php',
            'index.blade.php.stub'     => $path . '/resources/views/index.blade.php',
        ];

        // Copia e faz o replace do MEU_APP para os arquivos de stub
        foreach ($stubMap as $stubFile => $destination) {
            $source = $path . '/stubs/' . $stubFile;
            if (file_exists($source)) {
                $content = file_get_contents($source);
                $content = str_replace('MEU_APP', $projectName, $content);
                file_put_contents($destination, $content);
            }
        }

        // Processa o README.md que já está na raiz do projeto
        $readmePath = $path . '/README.md';
        if (file_exists($readmePath)) {
            $readmeContent = file_get_contents($readmePath);
            $readmeContent = str_replace('MEU_APP', $projectName, $readmeContent);
            file_put_contents($readmePath, $readmeContent);
        }

        // Anexa o stub de rotas ao final do web.php
        $routeStub = $path . '/stubs/web_routes.php.stub';
        if (file_exists($routeStub)) {
            $routeContent = file_get_contents($routeStub);
            file_put_contents($path . '/routes/web.php', $routeContent, FILE_APPEND);
        }

        // Limpa e remove a pasta stubs do projeto final
        array_map('unlink', glob("$path/stubs/*.*"));
        @rmdir($path . '/stubs');
    }

    private static function setupEnvironmentFiles(string $path, string $projectName)
    {
        $stubPath = $path . '/stubs/.env.stub';
        if (!file_exists($stubPath)) return;

        $envContent = file_get_contents($stubPath);

        // Prepara o conteúdo do .env.example substituindo MEU_APP
        $envExampleContent = str_replace('MEU_APP', $projectName, $envContent);
        file_put_contents($path . '/.env.example', $envExampleContent, FILE_APPEND);

        // Prepara o conteúdo do .env (com APP_URL para localhost:8000)
        $envLocalContent = str_replace("APP_URL=http://{$projectName}", "APP_URL=http://127.0.0.1:8000", $envExampleContent);
        file_put_contents($path . '/.env', $envLocalContent, FILE_APPEND);
    }
}