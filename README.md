# Starter Next Generation

Criando uma aplicação laravel `exemploapp`: 

    composer create-project uspdev/starter-ng exemploapp

Ou com Docker:

    docker run --rm -it \
      -v $(pwd):/app \
      -u $(id -u):$(id -g) \
      composer:latest \
      composer create-project laravel/laravel exemploapp

Este projeto possui instalado e configurado:

- **Laravel Framework**
- **Laravel USP Theme (`uspdev/laravel-usp-theme`)**: Layout padrão institucional.
- **Senha Única Socialite (`uspdev/senhaunica-socialite`)**: Autenticação OAuth1 integrada com `spatie/laravel-permission`.
- **USP Replicado (`uspdev/replicado`)**: Conexão com bases corporativas USP.
- **Laravel Dusk**: Testes de interface configurados.
- **Simple CRUD Generator (`uspdev/simple-crud-generator`)**: Gerador rápido de scaffolding.
- **Docker Stack**: Apache PHP, MariaDB 11, phpMyAdmin, Selenium e SenhaÚnica Faker.

### Se for rodar o senhaunica-faker é necessário em `/etc/hosts`:

    127.0.0.1 auth.local


### Desenvolvimento

Para incluir novos recursos e testar localmente, suponha que você esteja na pasta meus-projetos:

    meus-projetos
    ├── starter-ng # Essa lib
    └── (novo projeto vai ser criado aqui depois do commando abaixo)

Rode na pasta meus-projetos:

    docker run --rm -it \
    -v $(pwd):/app \
    -u $(id -u):$(id -g) \
    composer:latest \
    composer create-project uspdev/starter-ng exemploapp \
    --repository='{"type": "path", "url": "/app/starter-ng", "options": {"symlink": false}}' \
    --stability=dev
