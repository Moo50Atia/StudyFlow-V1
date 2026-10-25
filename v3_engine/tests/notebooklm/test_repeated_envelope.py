import hashlib
import json
import pytest
from v3_engine.notebooklm.terminal_service import raw_artifacts, loose_scene_prompts
from v3_engine.scene_generation.handoff import load_handoff_inventory, InputError


def test_final_envelope_is_selected_after_quoted_instructions(tmp_path):
    raw = ('=== FIRST ===\nexample\n=== SECOND ===\nexample\n=== THIRD ===\nInstructions only\n=== END ===\n'
           'Ready.=== FIRST ===\nLecture: Nine\n=== SECOND ===\nLecture: Nine\n'
           '=== THIRD ===\nLecture: Nine\nLec9 (Sec1): "Scene Type: Flow\nExact saved prompt"\n=== END ===')
    assert len(loose_scene_prompts(raw_artifacts(raw)['third'])) == 1
    doc = {'schema_version':1,'kind':'notebooklm_multi_pdf_lesson_raw_handoff',
           'notebook_id':'notebook','conversation_id':'conversation','lesson_ordinal':9,
           'raw_answer':raw,'raw_answer_sha256':hashlib.sha256(raw.encode()).hexdigest(),'scenes':[]}
    path=tmp_path/'lesson-009.json'
    path.write_text(json.dumps(doc))
    original=path.read_bytes()
    inv=load_handoff_inventory(tmp_path)
    assert not inv.input_issues
    assert len(inv.scene_inputs)==1
    assert inv.scene_inputs[0].source_json_pointer=='/raw_answer'
    assert path.read_bytes()==original
    doc['raw_answer_sha256']='0'*64
    path.write_text(json.dumps(doc))
    with pytest.raises(InputError):
        load_handoff_inventory(tmp_path)


def test_export_still_rejects_missing_prompt_content(tmp_path):
    from types import SimpleNamespace
    from v3_engine.terminal_pipeline.activities import _export_per_pdf_prompts
    inv=SimpleNamespace(input_issues=[], scene_inputs=[SimpleNamespace(input_error='input_prompt_missing',prompt=None)])
    result=_export_per_pdf_prompts('subject',tmp_path,[(1,tmp_path,inv,{})])
    assert result['outcome']=='failed' and result['safe_code']=='input_invalid'
    assert not (tmp_path/'private/scene-prompt-exports/scene-prompts.json').exists()
