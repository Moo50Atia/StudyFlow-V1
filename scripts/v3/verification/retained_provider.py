"""Test-only NotebookLM response replay. Never imported by product entrypoints.

Used by verify_worker_workflow.py with real Temporal and the production daemon.
Only the provider session is replaced; workflow activities and CLI remain real.
"""
from contextlib import asynccontextmanager
import hashlib
import json
import os
from pathlib import Path

from v3_engine.notebooklm.ports import (
    ChatFormatSettings, NotebookInventory, NotebookRef, SourceReceipt, TurnAnswer,
)
from v3_engine.lessons.canonical_prompts import DIVIDE_PROMPT, EXECUTION_PROMPT


class RetainedProvider:
    def __init__(self, fixture, trace):
        self.fixture = json.loads(Path(fixture).read_text())
        self.trace = Path(trace)
        self.sources = {}
        self.conversations = {}
        self.current = None
        self.sequence = 0
        self.format = ''
        self.files = self.conversation = self

    def record(self, kind, **fields):
        with self.trace.open('a') as stream:
            stream.write(json.dumps({'kind': kind, **fields}) + '\n')

    @asynccontextmanager
    async def open(self, profile_ref=None, storage_path=None):
        yield self

    async def close(self):
        pass

    async def verify_owner(self, proof_notebook_id):
        self.record('verify_owner')
        return 'owner'

    async def create_notebook(self, title):
        self.record('create_notebook')
        return NotebookRef('retained-provider-notebook', title)

    async def get_notebook(self, id):
        return NotebookInventory(id, 'Retained test notebook', list(self.sources))

    async def list_notebooks(self):
        return [NotebookRef('retained-provider-notebook', 'Retained test notebook')]

    async def upload_file(self, notebook_id, local_path, mime_type, title):
        digest = hashlib.sha256(Path(local_path).read_bytes()).hexdigest()
        source_id = 'source-' + digest
        ordinal = None
        if mime_type == 'application/pdf':
            ordinal = next(row['pdf_ordinal'] for row in self.fixture['parts'] if row['pdf_sha256'] == digest)
        self.sources[source_id] = {'title': title, 'mime': mime_type, 'ordinal': ordinal}
        self.record('upload', pdf_ordinal=ordinal, sha256=digest, title=title)
        return SourceReceipt(source_id, title, 'ready', mime_type)

    async def list_sources(self, notebook_id):
        return [SourceReceipt(k, v['title'], 'ready', v['mime']) for k, v in self.sources.items()]

    async def get_source(self, notebook_id, source_id):
        return next(x for x in await self.list_sources(notebook_id) if x.source_id == source_id)

    async def configure_format(self, notebook_id, format_text):
        self.format = format_text
        self.record('configure')

    async def get_format(self, notebook_id):
        return ChatFormatSettings('custom', 'longer', self.format)

    async def current_conversation_id(self, notebook_id):
        return self.current

    async def history(self, notebook_id, conversation_id, limit=100):
        return self.conversations.get(conversation_id, [])[-limit:]

    async def reset_conversation(self, notebook_id, conversation_id):
        assert self.current == conversation_id
        self.current = None
        self.record('reset', conversation_id=conversation_id)

    async def ask_once(self, notebook_id, question, source_ids, conversation_id=None,
                       fresh_after_conversation_id=None):
        ordinals = [self.sources[s]['ordinal'] for s in source_ids if self.sources[s]['ordinal'] is not None]
        assert len(ordinals) == 1
        ordinal = ordinals[0]
        part = self.fixture['parts'][ordinal - 1]
        if self.current is None:
            self.sequence += 1
            self.current = f'retained-conversation-{self.sequence}'
            self.conversations[self.current] = []
        assert conversation_id in (None, self.current)
        history = self.conversations[self.current]
        lesson = None
        if question == DIVIDE_PROMPT:
            kind, answer = 'divide', part['divide_answer']
        elif question == EXECUTION_PROMPT:
            kind, answer = 'execution', part['execution_answer']
        else:
            assert question in ('Start with First lesson', 'Continue'), question[:100]
            lesson = len(history) - 1
            kind, answer = 'lesson', part['lessons'][lesson - 1]['raw_answer']
        history.append((question, answer))
        self.record('answer', pdf_ordinal=ordinal, turn_kind=kind, lesson_ordinal=lesson,
                    answer_sha256=hashlib.sha256(answer.encode()).hexdigest())
        return TurnAnswer(answer, self.current, len(history))


async def main():
    # This patch is confined to this test process. No workflow/activity/CLI is
    # stubbed, and the outbound daemon is an unmodified separate process.
    from v3_engine.notebooklm import terminal_service
    provider = RetainedProvider(os.environ['WATCHER_REPLAY_FIXTURE'], os.environ['WATCHER_PROVIDER_TRACE'])
    terminal_service.SdkSessionProvider = lambda: provider
    from v3_engine.terminal_pipeline.cli import connect_client
    from v3_engine.terminal_pipeline.worker import run_workers
    await run_workers(await connect_client())


if __name__ == '__main__':
    import asyncio
    asyncio.run(main())
