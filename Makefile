.PHONY: up down build migrate load-fias test bash logs ps composer-install

# Переменные
DOCKER_COMPOSE 	= docker compose
EXEC_APP 		= docker exec -it yii2-app
EXEC_PHP 		= $(EXEC_APP) php
EXEC_COMPOSER 	= $(EXEC_APP) composer

# Запуск всех контейнеров
up:
	$(DOCKER_COMPOSE) up -d

# Остановка и удаление контейнеров
down:
	$(DOCKER_COMPOSE) down

# Сборка контейнера
build:
	$(DOCKER_COMPOSE) up -d --build

# Применение миграций БД
migrate:
	$(EXEC_PHP) yii migrate --interactive=0

# Загрузка данных ФИАС
load-fias:
	$(EXEC_PHP) yii fias/load

# Запуск всех тестов
test:
	$(EXEC_PHP) vendor/bin/codecept run

# Вход в bash-оболочку контейнера
bash:
	$(EXEC_APP) bash

# Просмотр логов всех контейнеров
logs:
	$(DOCKER_COMPOSE) logs -f

# Список запущенных контейнеров
ps:
	$(DOCKER_COMPOSE) ps

# Установка всех PHP-зависимостей (включая dev)
composer-install:
	$(EXEC_COMPOSER) install