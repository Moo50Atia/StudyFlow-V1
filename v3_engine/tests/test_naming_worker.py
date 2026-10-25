import hashlib
from pathlib import Path

import pytest

from scripts.v3.verification.naming_worker import named_source_request


def test_naming_profile_changes_actual_filename_and_preserves_the_sealed_pdf(tmp_path):
    source = tmp_path/'hosted-pdf-0001.pdf'
    data = b'%PDF captured immutable content'
    source.write_bytes(data)
    request = {'subject_run_id': 'run', 'source_path': str(source), 'mime_type': 'application/pdf',
               'title': source.name, 'expected_sha256': hashlib.sha256(data).hexdigest()}
    profile = {'pdf_name_template': 'Mechanics Part {ordinal}.pdf', 'framework_name': 'Teaching Instructions.txt'}

    actual = named_source_request(request, profile, tmp_path/'terminal')

    assert actual['title'] == 'Mechanics Part 1.pdf'
    assert Path(actual['source_path']).name == actual['title']
    assert Path(actual['source_path']).read_bytes() == source.read_bytes() == data
    assert actual['expected_sha256'] == request['expected_sha256']
    assert source.name == request['title']


def test_naming_profile_rejects_changed_bytes_before_provider_dispatch(tmp_path):
    source = tmp_path/'hosted-pdf-0001.pdf'
    source.write_bytes(b'changed')
    request = {'subject_run_id': 'run', 'source_path': str(source), 'mime_type': 'application/pdf',
               'title': source.name, 'expected_sha256': '0'*64}
    with pytest.raises(ValueError, match='captured snapshot'):
        named_source_request(request, {'pdf_name_template': 'Mechanics Part {ordinal}.pdf'}, tmp_path/'terminal')
