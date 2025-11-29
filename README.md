# Road Runner experimenting

This repo contains some experiments with the RoadRunner: a high-performance PHP application server, load-balancer, and process manager written in Golang.

https://roadrunner.dev/

### How to run the episodes
cd into the episode folder and run `rr serve`

For example:
```
cd "episodes/0 - First Contact"
docker-compose down -v
docker-compose build --no-cache
docker-compose up
```