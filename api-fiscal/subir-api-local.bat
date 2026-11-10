@echo off
rem Para o container que ocupa a porta 8081 e sobe a API desta pasta (docker/compose.yml + .env).
cd /d "%~dp0"
(
echo === containers antes
docker ps --format "{{.Names}} - {{.Ports}} - {{.Label \"com.docker.compose.project.working_dir\"}}"
for /f "delims=" %%c in ('docker ps --filter "publish=8081" --format "{{.Names}}"') do (
  echo === parando %%c
  docker stop %%c
)
echo === subindo
docker compose --env-file .env -f docker/compose.yml up -d --build
echo === containers depois
docker compose --env-file .env -f docker/compose.yml ps
echo === FIM
) > subir-api-local.log 2>&1
