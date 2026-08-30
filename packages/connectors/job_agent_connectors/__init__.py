"""Job source connectors.

The connector contract lands in Phase 2 of ``job-agent-plan.md``. It is declared
here so the API and worker can depend on a stable import path from Phase 0.
"""

from job_agent_connectors.base import DiscoveryBatch, JobSource, RawJob

__all__ = ["DiscoveryBatch", "JobSource", "RawJob"]
