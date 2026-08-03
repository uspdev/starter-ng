# Starter Next Generation

Gerador de projetos laravel no padrão USPdev. Esse projeto faz:

- Instala a última versão do **Laravel**
- Instala e configura o **Laravel USP Theme (`uspdev/laravel-usp-theme`)**
- Instala e configura o **Senha Única Socialite (`uspdev/senhaunica-socialite`)**
- Prepara um Dockerfile e docker-compose.yml básicos
- Criar um IndexController, rota e blade para index

Na seção de dev do composer instala:

**Laravel Dusk**
- **Simple CRUD Generator (`uspdev/simple-crud-generator`)**: Gerador rápido de scaffolding.

Criando uma aplicação laravel `exemploapp`: 

    composer create-project uspdev/starter-ng exemploapp
    composer dump-autoload -o
    echo '\nDB_CONNECTION=sqlite' >> .env
    php artisan migrate --force
    php artisan serve

Ou com Docker:

    docker run --rm -it \
      -v $(pwd):/app \
      -u $(id -u):$(id -g) \
      composer:latest \
      composer create-project uspdev/starter-ng exemploapp

    cd exemploapp
    docker compose up --build -d
    docker exec -it exemploapp composer dump-autoload -o
    docker exec -it exemploapp php artisan migrate

Acessar aplicação em [http://127.0.0.1:8000](http://127.0.0.1:8000)

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
