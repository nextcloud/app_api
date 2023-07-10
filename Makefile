.DEFAULT_GOAL := help

.PHONY: doc
.PHONY: html
doc html:
	$(MAKE) -C docs html

.PHONY: help
help:
	@echo "Welcome to AppEcosystemV2 development. Please use \`make <target>\` where <target> is one of"
	@echo "  doc                make HTML docs"
	@echo "  html               make HTML docs"
