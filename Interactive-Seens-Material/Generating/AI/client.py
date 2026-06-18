"""
Pluggable AI client abstraction.

Supports multiple LLM providers (Gemini, OpenAI) with a unified interface.
All pipeline stages use this client for AI calls — never call provider APIs directly.
"""

import json
import logging
import time
from typing import Optional, Type, TypeVar

from pydantic import BaseModel

from Generating.config import (
    AI_API_KEY,
    AI_MAX_INPUT_TOKENS,
    AI_MODEL,
    AI_PROVIDER,
    AI_TEMPERATURE,
)

logger = logging.getLogger(__name__)

T = TypeVar("T", bound=BaseModel)


class AIClientError(Exception):
    """Raised when an AI API call fails."""
    pass


class AIClient:
    """
    Unified interface for LLM calls.

    Usage:
        client = AIClient()
        response = client.generate("Extract the table of contents from this text...")
        structured = client.generate_structured(prompt, MyPydanticModel)
    """

    def __init__(
        self,
        provider: Optional[str] = None,
        api_key: Optional[str] = None,
        model: Optional[str] = None,
        temperature: Optional[float] = None,
        max_input_tokens: Optional[int] = None,
    ):
        self.provider = provider or AI_PROVIDER
        self.api_key = api_key or AI_API_KEY
        self.model = model or AI_MODEL
        self.temperature = temperature if temperature is not None else AI_TEMPERATURE
        self.max_input_tokens = max_input_tokens or AI_MAX_INPUT_TOKENS

        # Usage tracking
        self.total_prompt_tokens = 0
        self.total_completion_tokens = 0

        if not self.api_key:
            raise AIClientError(
                "No API key provided. Set STUDYFLOW_AI_API_KEY environment variable "
                "or pass api_key to AIClient()."
            )

        self._client = None
        self._init_client()

    def _init_client(self):
        """Initialize the provider-specific client."""
        if self.provider == "gemini":
            self._init_gemini()
        elif self.provider == "openai":
            self._init_openai()
        else:
            raise AIClientError(f"Unsupported AI provider: {self.provider}")

    def _init_gemini(self):
        """Initialize Google Gemini client."""
        try:
            import google.generativeai as genai
            genai.configure(api_key=self.api_key)
            self._client = genai.GenerativeModel(
                model_name=self.model,
                generation_config=genai.GenerationConfig(
                    temperature=self.temperature,
                ),
            )
            logger.info(f"Gemini client initialized with model: {self.model}")
        except ImportError:
            raise AIClientError(
                "google-generativeai package not installed. "
                "Run: pip install google-generativeai"
            )

    def _init_openai(self):
        """Initialize OpenAI client."""
        try:
            from openai import OpenAI
            self._client = OpenAI(api_key=self.api_key)
            logger.info(f"OpenAI client initialized with model: {self.model}")
        except ImportError:
            raise AIClientError(
                "openai package not installed. Run: pip install openai"
            )

    def generate(
        self,
        prompt: str,
        system_prompt: Optional[str] = None,
        response_format: str = "text",
        max_retries: int = 3,
        retry_delay: float = 2.0,
    ) -> str:
        """
        Send a prompt to the LLM and return the response text.

        Args:
            prompt: The user prompt to send.
            system_prompt: Optional system-level instructions.
            response_format: "text" or "json" — hints to the model.
            max_retries: Number of retries on transient failures.
            retry_delay: Base delay between retries (exponential backoff).

        Returns:
            The model's response as a string.
        """
        for attempt in range(max_retries):
            try:
                if self.provider == "gemini":
                    return self._generate_gemini(prompt, system_prompt, response_format)
                elif self.provider == "openai":
                    return self._generate_openai(prompt, system_prompt, response_format)
            except Exception as e:
                if attempt < max_retries - 1:
                    delay = retry_delay * (2 ** attempt)
                    logger.warning(
                        f"AI call failed (attempt {attempt + 1}/{max_retries}): {e}. "
                        f"Retrying in {delay}s..."
                    )
                    time.sleep(delay)
                else:
                    raise AIClientError(
                        f"AI call failed after {max_retries} attempts: {e}"
                    ) from e

    def _generate_gemini(
        self, prompt: str, system_prompt: Optional[str], response_format: str
    ) -> str:
        """Generate response using Gemini."""
        full_prompt = prompt
        if system_prompt:
            full_prompt = f"{system_prompt}\n\n---\n\n{prompt}"

        if response_format == "json":
            full_prompt += (
                "\n\nIMPORTANT: Respond with ONLY valid JSON. "
                "No markdown code blocks, no explanations, no extra text. "
                "Just the raw JSON object."
            )

        response = self._client.generate_content(full_prompt)

        if not response or not response.text:
            raise AIClientError("Gemini returned empty response")

        if hasattr(response, "usage_metadata"):
            self.total_prompt_tokens += getattr(response.usage_metadata, "prompt_token_count", 0)
            self.total_completion_tokens += getattr(response.usage_metadata, "candidates_token_count", 0)

        return response.text.strip()

    def _generate_openai(
        self, prompt: str, system_prompt: Optional[str], response_format: str
    ) -> str:
        """Generate response using OpenAI."""
        messages = []
        if system_prompt:
            messages.append({"role": "system", "content": system_prompt})
        messages.append({"role": "user", "content": prompt})

        kwargs = {
            "model": self.model,
            "messages": messages,
            "temperature": self.temperature,
        }

        if response_format == "json":
            kwargs["response_format"] = {"type": "json_object"}

        response = self._client.chat.completions.create(**kwargs)
        
        if hasattr(response, "usage") and response.usage:
            self.total_prompt_tokens += response.usage.prompt_tokens
            self.total_completion_tokens += response.usage.completion_tokens
            
        return response.choices[0].message.content.strip()

    def generate_json(
        self,
        prompt: str,
        system_prompt: Optional[str] = None,
        max_retries: int = 3,
    ) -> dict:
        """
        Send a prompt and parse the response as JSON.

        Returns:
            Parsed JSON as a Python dict.

        Raises:
            AIClientError: If the response is not valid JSON after retries.
        """
        raw = self.generate(
            prompt, system_prompt, response_format="json", max_retries=max_retries
        )

        # Strip markdown code blocks if the model wraps its response
        cleaned = raw
        if cleaned.startswith("```json"):
            cleaned = cleaned[7:]
        elif cleaned.startswith("```"):
            cleaned = cleaned[3:]
        if cleaned.endswith("```"):
            cleaned = cleaned[:-3]
        cleaned = cleaned.strip()

        try:
            return json.loads(cleaned)
        except json.JSONDecodeError as e:
            raise AIClientError(
                f"AI response is not valid JSON: {e}\n"
                f"Raw response (first 500 chars): {raw[:500]}"
            ) from e

    def generate_structured(
        self,
        prompt: str,
        schema: Type[T],
        system_prompt: Optional[str] = None,
        max_retries: int = 3,
    ) -> T:
        """
        Send a prompt and parse the response into a Pydantic model.

        Args:
            prompt: The user prompt.
            schema: A Pydantic BaseModel class to validate against.
            system_prompt: Optional system instructions.
            max_retries: Number of retries.

        Returns:
            An instance of the provided Pydantic model.
        """
        # Augment prompt with the expected JSON schema
        schema_json = json.dumps(schema.model_json_schema(), indent=2)
        augmented_prompt = (
            f"{prompt}\n\n"
            f"Your response must be valid JSON matching this schema:\n"
            f"```json\n{schema_json}\n```"
        )

        data = self.generate_json(augmented_prompt, system_prompt, max_retries)

        try:
            return schema.model_validate(data)
        except Exception as e:
            raise AIClientError(
                f"AI response does not match schema {schema.__name__}: {e}"
            ) from e

    def estimate_tokens(self, text: str) -> int:
        """
        Estimate the number of tokens in a text string.

        Uses a simple character-based heuristic. For production,
        consider using tiktoken (OpenAI) or the Gemini tokenizer.
        """
        from Generating.config import CHARS_PER_TOKEN
        return int(len(text) / CHARS_PER_TOKEN)

    def fits_in_context(self, text: str) -> bool:
        """Check if text fits within the configured context window."""
        return self.estimate_tokens(text) <= self.max_input_tokens

    def get_usage(self) -> dict:
        """Get accumulated token usage and estimated cost."""
        from Generating.config import COST_PER_1K_PROMPT_TOKENS, COST_PER_1K_COMPLETION_TOKENS
        cost = (self.total_prompt_tokens / 1000.0 * COST_PER_1K_PROMPT_TOKENS) + \
               (self.total_completion_tokens / 1000.0 * COST_PER_1K_COMPLETION_TOKENS)
        return {
            "total_prompt_tokens": self.total_prompt_tokens,
            "total_completion_tokens": self.total_completion_tokens,
            "estimated_cost_usd": round(cost, 6),
        }
