# ARCHE-exif

A dissemination service for the [ARCHE Suite](https://acdh-oeaw.github.io/arche-docs/) providing repository resource EXIF metadata as a JSON.

* Supports both local access and downloading remote resources
* Allows to limit supported resource URL namespaces and downloaded resource size
* As for now **doesn't** support caching.

## REST API

`{deploymentUrl}?id={URL-encoded resource URL}`

## Deployment

* Build the docker image providing the runtime environment
  ```bash
  docker build -t arche-exif .
  ```
* Run a docker container mounting the arche-core data dir under `/data` and specyfying the configuration using env vars, e.g.:
  ```bash
  docker run --name arche-exif -p 80:80 \
      -e MAXLEVEL=2 \
      -e BASEURL=https://arche.acdh.oeaw.ac.at/api/ \
      -e MAXSIZE=1000
      -e 'ALLOWEDNMSP=https://arche-curation.acdh.oeaw.ac.at/api/,https://arche-dev.acdh-dev.oeaw.ac.at/api/' \
      -v pathToArcheDataDir:/data \
      arche-exif
  ```
  available configuration env vars:
  * `MAXLEVEL=2`: local ARCHE storage subdirectories nesting level
  * `BASEURL`: local ARCHE instance base URL (resources in this namespace are accessed locally, resources outside this namespace are being downloaded)
  * `ALLOWEDNMSP`: comma-separated list of namespaces (URI prefixes) allowed to be downloaded
  * `MAXSIZE=1000`: maximum size in MB of the resources outside of the `BASEURL`
* Test
  ```bash
  curl -i http://127.0.0.1/?id=someResourceId
  ```

