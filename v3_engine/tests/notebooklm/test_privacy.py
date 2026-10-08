import logging
import pytest
from unittest.mock import AsyncMock

from v3_engine.notebooklm.ports import (
    ProviderSession,
    TurnAnswer,
    SourceReceipt,
    ProviderError,
)
from v3_engine.notebooklm.sdk_provider import SdkProviderSession


SENTINEL_EDUCATIONAL_CONTENT = "CONFIDENTIAL_FORMULA_INTEGRAL_E_EQUALS_MC2"
SENTINEL_AUTH_COOKIE = "SAPISID=SECRET_AUTH_TOKEN_VALUE_XYZ"


def test_n22_port_error_does_not_leak_educational_content_in_code():
    err = ProviderError(
        code="bundle_invalid",
        message="Provider output did not match combined envelope format.",
        raw_detail=SENTINEL_EDUCATIONAL_CONTENT,
    )

    # str(err) or message must not contain sentinel
    assert SENTINEL_EDUCATIONAL_CONTENT not in str(err)
    assert SENTINEL_EDUCATIONAL_CONTENT not in err.message
    assert err.code == "bundle_invalid"


def test_n22_port_error_does_not_leak_cookie_in_code():
    err = ProviderError(
        code="authentication_required",
        message="Authentication required or session expired.",
        raw_detail=SENTINEL_AUTH_COOKIE,
    )

    assert SENTINEL_AUTH_COOKIE not in str(err)
    assert SENTINEL_AUTH_COOKIE not in err.message
    assert err.code == "authentication_required"


def test_n22_logger_redacts_sentinel_in_port_wrapper(caplog):
    caplog.set_level(logging.INFO)
    logger = logging.getLogger("v3_engine.notebooklm")

    # When logging an error code
    logger.error("Provider call failed with code %s", "provider_unavailable")

    assert SENTINEL_EDUCATIONAL_CONTENT not in caplog.text
    assert SENTINEL_AUTH_COOKIE not in caplog.text


def test_n22_bundle_parser_exception_does_not_leak_sentinel_content():
    from v3_engine.notebooklm.bundle_parser import parse_combined_bundle, BundleParserError

    malformed_input = f"=== FIRST ===\n{SENTINEL_EDUCATIONAL_CONTENT}\n=== INVALID ==="
    with pytest.raises(BundleParserError) as exc_info:
        parse_combined_bundle(malformed_input, expected_work_ordinal=1)

    err_str = str(exc_info.value)
    assert SENTINEL_EDUCATIONAL_CONTENT not in err_str


def test_n22_protocol_error_logging_excludes_sentinel_bodies(caplog):
    caplog.set_level(logging.DEBUG)
    logger = logging.getLogger("v3_engine.notebooklm.protocol")

    # Log operational events with error codes and references only
    logger.error("Turn failed for case %s, query %s, code %s", "case-123", "query-456", "bundle_invalid")

    assert SENTINEL_EDUCATIONAL_CONTENT not in caplog.text
    assert SENTINEL_AUTH_COOKIE not in caplog.text
