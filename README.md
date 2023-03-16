# ARCHE-exif

A dissemination service for the [ARCHE Suite](https://acdh-oeaw.github.io/arche-docs/) providing repository resource EXIF metadata as a JSON.

## Deployment

* Build the docker image providing the runtime environment
  ```bash
  docker build -t arche-exif .
  ```
* Run a docker container mounting the arche-core data dir under `/data` and specyfying the subdirectories nesting level in the `MAXLEVEL` env var, e.g.
  ```bash
  docker run --name arche-exif -p 80:80 -e MAXLEVEL=2 -v pathToArcheDataDir:/data arche-exif
  ```
* Test
  ```bash
  curl -i http://127.0.0.1/?id=someResourceId
  ```

