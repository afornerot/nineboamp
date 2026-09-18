"""
Configuration loader for LlamaIndex Agent.
Reads from .env / .env.local (Symfony convention) and prompt files.
"""
import os
import re
from pathlib import Path


def load_env():
    """Load environment variables from .env and .env.local (Symfony convention)."""
    env_files = [
        Path("/app/.env.local"),
        Path("/app/.env"),
    ]
    
    for env_file in env_files:
        if env_file.exists():
            with open(env_file) as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        key, _, value = line.partition("=")
                        key = key.strip()
                        value = value.strip().strip('"').strip("'")
                        if key not in os.environ:
                            os.environ[key] = value


def get_ai_config() -> dict:
    """Get AI configuration from environment variables."""
    return {
        "provider": os.environ.get("AI_PROVIDER", "openai"),
        "model": os.environ.get("AI_MODEL", "gpt-4o"),
        "api_key": os.environ.get("AI_API_KEY", ""),
        "base_url": os.environ.get("AI_BASE_URL", "https://api.openai.com/v1"),
    }


def get_project_dir() -> str:
    """Get the project directory."""
    return os.environ.get("PROJECT_DIR", "/app")


def get_prompts_dir() -> Path:
    """Get the prompts directory."""
    return Path(get_project_dir()) / "src" / "DataFixtures" / "data" / "scoring"


def load_prompt(name: str) -> str | None:
    """
    Load a prompt from .md file.
    Similar to PromptLoader in Symfony.
    
    Args:
        name: Prompt name without extension (e.g., 'chat.context', 'chat.query_planning')
    
    Returns:
        The prompt body content, or None if not found.
    """
    prompt_file = get_prompts_dir() / f"{name}.md"
    
    if not prompt_file.exists():
        return None
    
    with open(prompt_file, 'r') as f:
        content = f.read()
    
    # Parse front-matter (between --- and ---)
    content = content.strip()
    if content.startswith('---'):
        end = content.find('\n---', 3)
        if end != -1:
            content = content[end + 4:].strip()
    
    return content


def get_system_prompt() -> str | None:
    """Load the system prompt from chat.context.md."""
    return load_prompt("chat.context")


def get_planning_prompt() -> str | None:
    """Load the query planning prompt from chat.query_planning.md."""
    return load_prompt("chat.query_planning")


# Load environment on module import
load_env()
