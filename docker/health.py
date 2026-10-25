"""Infrastructure probes; provider authentication is deliberately excluded."""
import asyncio
import os
import sys
import urllib.request
from datetime import datetime, timezone
from temporalio.client import Client
from temporalio.api.workflowservice.v1 import DescribeTaskQueueRequest
from temporalio.api.taskqueue.v1 import TaskQueue
from temporalio.api.enums.v1 import TaskQueueType

async def probe():
    client = await Client.connect(os.environ['V3_TERMINAL_TEMPORAL_ADDRESS'], namespace='v3-terminal')
    await client.service_client.check_health()
    if sys.argv[1] == 'bridge':
        with urllib.request.urlopen('http://127.0.0.1:8765/health', timeout=3) as response:
            assert response.status == 200
    else:
        # Every required queue must have a recent poll from this container's worker.
        for queue, kind in [('v3-terminal-control', TaskQueueType.TASK_QUEUE_TYPE_WORKFLOW),
                            ('v3-terminal-io', TaskQueueType.TASK_QUEUE_TYPE_ACTIVITY),
                            ('v3-terminal-build', TaskQueueType.TASK_QUEUE_TYPE_ACTIVITY)]:
            result = await client.workflow_service.describe_task_queue(DescribeTaskQueueRequest(
                namespace='v3-terminal', task_queue=TaskQueue(name=queue), task_queue_type=kind))
            assert any(os.environ['HOSTNAME'] in p.identity and
                       (datetime.now(timezone.utc)-p.last_access_time.ToDatetime(tzinfo=timezone.utc)).total_seconds() < 120
                       for p in result.pollers), f'No recent worker poll on {queue}'

asyncio.run(asyncio.wait_for(probe(), timeout=10))
