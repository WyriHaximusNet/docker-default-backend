# default backend Docker image

This docker image is created to spice up your 404 default backends. It comes with a set of tags, one per design. Plus 
the `random` tag, the random tag will be randomly updated to point at one of the designs. [For a live demo visit this page.](https://default-backend.k8s.wyrihaximus.net/)

## Usage

```bash
docker run --rm -p 6969:6969 -p 9696:9696 wyrihaximusnet/default-backend:random
``` 

## Ports

* `6969` - Serves the `404` page
* `9696` - Serves prometheus metrics
