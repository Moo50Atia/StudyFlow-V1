from temporalio.client import Client
from v3_engine.settings import get_settings

async def get_temporal_client() -> Client:
    settings = get_settings()
    return await Client.connect(
        settings.temporal_address,
        namespace=settings.temporal_namespace,
    )
