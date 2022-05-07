SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it -v $(PWD):/app phpwall56
composer := $(docker) composer

docker-build:
	docker build -t phpwall56 .

bash:
	$(docker) bash

composer-install:
	$(composer) install

composer-up:
	$(composer) update $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

psalm:
	$(docker) vendor/bin/psalm
