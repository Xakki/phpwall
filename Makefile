SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it --rm -v $(PWD):/app -w /app xakki/phpwall-php:8.1
composer := $(docker) composer

docker-build:
	docker build -t xakki/phpwall-php:8.1 .

docker-push:
	docker push xakki/phpwall-php:8.1

bash:
	$(docker) bash

composer-i:
	$(composer) install --prefer-dist --no-scripts

composer-u:
	$(composer) update --prefer-dist --no-scripts $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

phpstan:
	$(composer) phpstan

phpunit:
	$(composer) phpunit

test:
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit
