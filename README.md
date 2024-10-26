# ARCHE-exif

[![Build status](https://github.com/acdh-oeaw/arche-exif/actions/workflows/deploy.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-exif/actions/workflows/deploy.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-exif/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-exif?branch=master)

A dissemination service for the [ARCHE Suite](https://acdh-oeaw.github.io/arche-docs/) providing repository resource EXIF metadata as a JSON.

* Supports both local access and downloading remote resources
* Allows to limit supported resource URL namespaces and downloaded resource size
* As for now **doesn't** support caching.

## REST API

`{deploymentUrl}?id={URL-encoded resource URL}`

## Deployment

See the .github/workflows/deploy.yaml
