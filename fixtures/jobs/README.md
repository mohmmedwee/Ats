# Recorded job-board fixtures

Sanitized responses shaped to each board's documented public schema. Company
names, URLs, and posting ids are invented; no real employer's data is stored
here.

Contract tests replay these through an `httpx.MockTransport`, so CI never calls
a real job board. When a board changes its schema, update the fixture and the
contract test fails until the adapter is updated with it — which is the point.

| File | Endpoint |
|---|---|
| `greenhouse_board.json` | `GET /v1/boards/{token}/jobs?content=true` |
| `lever_postings.json` | `GET /v0/postings/{site}?mode=json` |
| `ashby_board.json` | `GET /posting-api/job-board/{name}?includeCompensation=true` |
