SHELL = /bin/bash
### https://makefiletutorial.com/

-include ./.env
export

docker := docker run -it --rm -v $(PWD):/app -w /app xakki/php:8.1-fpm
docker84 := docker run -it --rm -v $(PWD):/app -w /app xakki/php:8.4-fpm
composer := $(docker) composer
composer84 := $(docker84) composer

bash:
	$(docker) bash

composer-i:
	$(composer) install --prefer-dist --no-scripts

composer-u:
	$(composer) update --prefer-dist $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

phpstan:
	$(composer) phpstan
	$(composer84) phpstan

phpunit:
	$(composer) phpunit

test:
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit

test-ui:
	@echo
	@echo "Start webserver on http://localhost:${WEB_PORT_EXT}"
	@echo
	docker compose up
	docker compose down
	@make clear-docker

clear-docker:
	docker compose rm -f
	docker volume rm phpwall_mysql_data