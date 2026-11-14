.PHONY: up down logs migrate-fresh seed demo test test-api test-web lint shell smoke

up:            ## Start the development stack
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f api worker storefront

migrate-fresh: ## Recreate the database with seed data
	docker compose exec api php artisan migrate:fresh --seed

seed:
	docker compose exec api php artisan db:seed

demo:          ## Demo catalog: 5 products, 2 mock suppliers (never in production)
	docker compose exec api php artisan db:seed --class=DemoCatalogSeeder
	docker compose exec api php artisan storage:link

smoke:         ## Browser journey against the running stack (needs playwright)
	cd apps/storefront && BASE_URL=http://localhost node e2e/smoke-journey.cjs ./e2e-output

test: test-api test-web

test-api:
	docker compose exec api php artisan test

test-web:
	docker compose exec storefront npm test

lint:
	docker compose exec api vendor/bin/pint --test
	docker compose exec storefront npm run lint
	docker compose exec storefront npm run typecheck

shell:
	docker compose exec api sh
