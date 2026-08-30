.PHONY: help install up down logs migrate revision api worker web test test-unit lint format typecheck check clean

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

install: ## Install Python and web dependencies
	uv sync --all-packages
	cd apps/web && npm install

up: ## Start the full stack with Docker Compose
	docker compose up --build -d
	@echo "api  -> http://localhost:8000/health"
	@echo "web  -> http://localhost:5173"

down: ## Stop the stack
	docker compose down

logs: ## Tail service logs
	docker compose logs -f

migrate: ## Apply database migrations
	uv run alembic upgrade head

revision: ## Create a migration: make revision m="add jobs table"
	uv run alembic revision --autogenerate -m "$(m)"

api: ## Run the API locally with reload
	uv run uvicorn job_agent_api.main:app --reload --host 0.0.0.0 --port 8000

worker: ## Run the background worker locally
	uv run dramatiq job_agent_worker.actors

web: ## Run the web app locally
	cd apps/web && npm run dev

test: ## Run the backend test suite
	uv run pytest

test-unit: ## Run unit tests only
	uv run pytest tests/unit

lint: ## Lint Python and web code
	uv run ruff check .
	cd apps/web && npm run lint

format: ## Format Python and web code
	uv run ruff format .
	uv run ruff check --fix .

typecheck: ## Type-check Python and web code
	uv run mypy apps packages
	cd apps/web && npm run typecheck

check: lint typecheck test ## Run every check CI runs

clean:
	find . -type d -name __pycache__ -prune -exec rm -rf {} +
	rm -rf .pytest_cache .mypy_cache .ruff_cache htmlcov .coverage
