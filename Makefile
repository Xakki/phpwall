SHELL = /bin/bash
### https://makefiletutorial.com/

-include ./.env
export

docker := docker run -it --rm -v $(PWD):/app -w /app xakki/php:5.6-fpm
composer := $(docker) composer

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

phpunit:
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
	docker volume rm phpwall_redis_data