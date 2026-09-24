"""
Agent API - Simple OpenAI-based agent with function calling.
Supports async jobs for long-running requests (chat, scoring, reports).
Hot-reload via docker volume mount (./agent).
"""
import os
import json
import uuid
import time
import httpx
from pathlib import Path
from typing import Optional
from fastapi import FastAPI, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

app = FastAPI(title="Nineboamp Agent", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

JOBS_DIR = "/tmp/jobs"

os.makedirs(JOBS_DIR, exist_ok=True)

for env_file in [Path("/app/.env.local"), Path("/app/.env")]:
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


def get_ai_config():
    """Get AI config from env."""
    return {
        "model": os.environ.get("AI_MODEL", "gpt-4o"),
        "api_key": os.environ.get("AI_API_KEY", ""),
        "base_url": os.environ.get("AI_BASE_URL", "https://api.openai.com/v1"),
    }


def load_prompt_from_file(filepath: str, default: str = "") -> str:
    """Load prompt from a markdown file, stripping frontmatter if present."""
    if os.path.exists(filepath):
        with open(filepath) as f:
            content = f.read()
            if content.startswith("---"):
                end = content.find("\n---", 3)
                if end != -1:
                    content = content[end + 4:].strip()
            return content
    return default


def load_system_prompt() -> str:
    """Load system prompt from markdown file."""
    return load_prompt_from_file(
        "/app/src/DataFixtures/data/scoring/chat.context.md",
        "Tu es un assistant expert en analyse de marchés publics."
    )


def load_scoring_role_prompt() -> str:
    """Load scoring role prompt from markdown file."""
    return load_prompt_from_file(
        "/app/src/DataFixtures/data/scoring/scoring.role.md",
        "Tu es un expert en analyse de marchés publics pour Cadoles."
    )


def load_scoring_user_prompt() -> str:
    """Load scoring user prompt from markdown file."""
    return load_prompt_from_file(
        "/app/src/DataFixtures/data/scoring/scoring.user.md",
        ""
    )


def load_report_system_prompt() -> str:
    """Load report system prompt from markdown file."""
    return load_prompt_from_file(
        "/app/src/DataFixtures/data/scoring/report.system.md",
        "Tu es un consultant senior en marchés publics pour Cadoles."
    )


def load_report_user_prompt() -> str:
    """Load report user prompt from markdown file."""
    return load_prompt_from_file(
        "/app/src/DataFixtures/data/scoring/report.user.md",
        ""
    )


def get_tools():
    """Return available tools for function calling."""
    return [
        {
            "type": "function",
            "function": {
                "name": "amoxtli_search",
                "description": "Recherche dans les documents indexés d'un marché via amoxtli. Retourne les sections pertinentes.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "query": {"type": "string", "description": "Requête de recherche"},
                        "market_id": {"type": "integer", "description": "ID du marché"},
                        "limit": {"type": "integer", "description": "Nombre max de résultats", "default": 5}
                    },
                    "required": ["query", "market_id"]
                }
            }
        },
        {
            "type": "function",
            "function": {
                "name": "list_documents",
                "description": "Liste les documents indexés dans amoxtli pour un marché.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "market_id": {"type": "integer", "description": "ID du marché"}
                    },
                    "required": ["market_id"]
                }
            }
        },
        {
            "type": "function",
            "function": {
                "name": "search_products",
                "description": "Recherche des produits par nom ou mots-clés. Retourne une liste de produits avec leur ID et description courte.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "query": {"type": "string", "description": "Nom ou mot-clé à rechercher dans les produits"}
                    },
                    "required": ["query"]
                }
            }
        },
        {
            "type": "function",
            "function": {
                "name": "get_product_info",
                "description": "Retourne les informations complètes d'un produit (description, keywords, sectors, fiche technique).",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "product_id": {"type": "integer", "description": "ID du produit"}
                    },
                    "required": ["product_id"]
                }
            }
        }
    ]


def get_scoring_tools():
    """Return only the product-related tools for scoring."""
    return [
        {
            "type": "function",
            "function": {
                "name": "search_products",
                "description": "Recherche des produits par nom ou mots-clés. Retourne une liste de produits avec leur ID et description courte.",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "query": {"type": "string", "description": "Nom ou mot-clé à rechercher dans les produits"}
                    },
                    "required": ["query"]
                }
            }
        },
        {
            "type": "function",
            "function": {
                "name": "get_product_info",
                "description": "Retourne les informations complètes d'un produit (description, keywords, sectors, fiche technique).",
                "parameters": {
                    "type": "object",
                    "properties": {
                        "product_id": {"type": "integer", "description": "ID du produit"}
                    },
                    "required": ["product_id"]
                }
            }
        }
    ]


async def execute_tool(tool_name: str, arguments: dict) -> str:
    """Execute a tool and return result."""
    import subprocess
    
    if tool_name == "amoxtli_search":
        workspace = f"/app/uploads/amoxtli/{arguments['market_id']}"
        if not os.path.isdir(f"{workspace}/.amoxtli"):
            return json.dumps({"error": f"Pas de workspace amoxtli pour le marché {arguments['market_id']}"})
        
        try:
            result = subprocess.run(
                ["/usr/local/bin/amoxtli", "-C", workspace, "search", arguments["query"], "--json", "-n", str(arguments.get("limit", 5))],
                capture_output=True, text=True, timeout=30
            )
            if result.returncode != 0:
                return json.dumps({"error": result.stderr})
            return result.stdout
        except Exception as e:
            return json.dumps({"error": str(e)})
    
    elif tool_name == "list_documents":
        workspace = f"/app/uploads/amoxtli/{arguments['market_id']}"
        if not os.path.isdir(f"{workspace}/.amoxtli"):
            return json.dumps({"error": f"Pas de workspace amoxtli pour le marché {arguments['market_id']}"})
        
        try:
            result = subprocess.run(
                ["/usr/local/bin/amoxtli", "-C", workspace, "doc", "list", "--json"],
                capture_output=True, text=True, timeout=30
            )
            if result.returncode != 0:
                return json.dumps({"error": result.stderr})
            return result.stdout
        except Exception as e:
            return json.dumps({"error": str(e)})
    
    elif tool_name == "search_products":
        query = arguments.get("query", "")
        mcp_secret = os.environ.get("MCP_SECRET", "")
        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.get(
                    "http://127.0.0.1/api/products/search",
                    params={"q": query},
                    headers={"Authorization": f"Bearer {mcp_secret}"}
                )
                if resp.status_code == 401:
                    return json.dumps({"error": "Non autorisé - MCP_SECRET manquant ou invalide"})
                return resp.text
        except Exception as e:
            return json.dumps({"error": str(e)})
    
    elif tool_name == "get_product_info":
        product_id = arguments.get("product_id")
        mcp_secret = os.environ.get("MCP_SECRET", "")
        try:
            async with httpx.AsyncClient(timeout=10.0) as client:
                resp = await client.get(
                    f"http://127.0.0.1/api/product/{product_id}",
                    headers={"Authorization": f"Bearer {mcp_secret}"}
                )
                if resp.status_code == 404:
                    return json.dumps({"error": f"Produit {product_id} non trouvé"})
                if resp.status_code == 401:
                    return json.dumps({"error": "Non autorisé - MCP_SECRET manquant ou invalide"})
                return resp.text
        except Exception as e:
            return json.dumps({"error": str(e)})
    
    return json.dumps({"error": f"Unknown tool: {tool_name}"})


class ChatRequest(BaseModel):
    market_id: int
    message: str
    history: list[dict[str, str]] = Field(default_factory=list)
    reset_session: bool = False


class ChatResponse(BaseModel):
    answer: str
    sources: list[str] = Field(default_factory=list)
    tools_used: list[str] = Field(default_factory=list)
    error: Optional[str] = None


class ScoreRequest(BaseModel):
    market_id: int
    title: str
    buyer: str
    description: str
    amount: str
    deadline: Optional[str] = None
    products: list[dict] = Field(default_factory=list)


class ScoreResponse(BaseModel):
    score: int
    priority: str
    explanation: str
    products: list[dict]
    tools_used: list[str] = Field(default_factory=list)
    error: Optional[str] = None


class ReportRequest(BaseModel):
    market_id: int
    title: str
    buyer: str
    description: str
    amount: str
    deadline: Optional[str] = None
    products: list[dict] = Field(default_factory=list)
    catalogue: str = ""
    chat_history: list[dict] = Field(default_factory=list)


class ReportResponse(BaseModel):
    markdown: str
    tools_used: list[str] = Field(default_factory=list)
    error: Optional[str] = None


def get_job_file(job_id: str) -> str:
    """Get the file path for a job."""
    return os.path.join(JOBS_DIR, f"{job_id}.json")


def save_job(job_id: str, data: dict) -> None:
    """Save job data to file."""
    with open(get_job_file(job_id), "w") as f:
        json.dump(data, f)


def load_job(job_id: str) -> Optional[dict]:
    """Load job data from file."""
    job_file = get_job_file(job_id)
    if os.path.exists(job_file):
        try:
            with open(job_file) as f:
                return json.load(f)
        except (json.JSONDecodeError, IOError):
            pass
    return None


def cleanup_old_jobs(max_age_seconds: int = 300) -> None:
    """Remove jobs older than max_age_seconds."""
    now = time.time()
    if os.path.exists(JOBS_DIR):
        for filename in os.listdir(JOBS_DIR):
            if filename.endswith(".json"):
                filepath = os.path.join(JOBS_DIR, filename)
                try:
                    if os.path.getmtime(filepath) < now - max_age_seconds:
                        os.remove(filepath)
                except OSError:
                    pass


async def process_chat(job_id: str, market_id: int, message: str, history: list[dict]) -> None:
    """Process chat in background and save result to job file."""
    save_job(job_id, {"status": "pending", "result": None})
    
    try:
        config = get_ai_config()
        
        messages = [{"role": "system", "content": load_system_prompt()}]
        
        for msg in history:
            messages.append({"role": msg.get("role", "user"), "content": msg["content"]})
        
        messages.append({"role": "user", "content": message})
        
        client = httpx.AsyncClient(timeout=120.0)
        try:
            response = await client.post(
                f"{config['base_url']}/chat/completions",
                headers={
                    "Authorization": f"Bearer {config['api_key']}",
                    "Content-Type": "application/json"
                },
                json={
                    "model": config["model"],
                    "messages": messages,
                    "tools": get_tools(),
                    "tool_choice": "auto"
                }
            )
            response.raise_for_status()
            data = response.json()
            
        except Exception as e:
            await client.aclose()
            save_job(job_id, {"status": "error", "result": {"answer": f"Erreur de connexion à l'API: {str(e)}", "sources": [], "tools_used": [], "error": str(e)}})
            return
        
        choice = data["choices"][0]
        message_content = choice["message"]
        
        tools_used = []
        
        while message_content.get("tool_calls"):
            for tool_call in message_content["tool_calls"]:
                tool_name = tool_call["function"]["name"]
                arguments = json.loads(tool_call["function"]["arguments"])
                tools_used.append(tool_name)
                
                tool_result = await execute_tool(tool_name, arguments)
                
                messages.append({
                    "role": "tool",
                    "tool_call_id": tool_call["id"],
                    "content": tool_result
                })
            
            try:
                response = await client.post(
                    f"{config['base_url']}/chat/completions",
                    headers={
                        "Authorization": f"Bearer {config['api_key']}",
                        "Content-Type": "application/json"
                    },
                    json={
                        "model": config["model"],
                        "messages": messages,
                        "tools": get_tools(),
                        "tool_choice": "auto"
                    }
                )
                response.raise_for_status()
                data = response.json()
                choice = data["choices"][0]
                message_content = choice["message"]
            except Exception as e:
                await client.aclose()
                save_job(job_id, {"status": "error", "result": {"answer": f"Erreur lors de l'exécution des outils: {str(e)}", "sources": [], "tools_used": tools_used, "error": str(e)}})
                return
        
        await client.aclose()
        save_job(job_id, {
            "status": "done",
            "result": {
                "answer": message_content.get("content", ""),
                "sources": [],
                "tools_used": tools_used
            }
        })
        
    except Exception as e:
        save_job(job_id, {"status": "error", "result": {"answer": f"Erreur inattendue: {str(e)}", "sources": [], "tools_used": [], "error": str(e)}})


async def process_score(job_id: str, request: ScoreRequest) -> None:
    """Process scoring in background and save result to job file."""
    save_job(job_id, {"status": "pending", "result": None})
    
    try:
        config = get_ai_config()
        
        role_prompt = load_scoring_role_prompt()
        user_prompt_template = load_scoring_user_prompt()
        
        products_str = ""
        if request.products:
            for p in request.products:
                name = p.get('name', 'Unknown')
                desc = p.get('description', '')
                keywords = p.get('keywords', '')
                sectors = p.get('sectors', '')
                products_str += f"- **{name}**"
                if desc:
                    products_str += f"\n  Description: {desc[:200]}"
                if keywords:
                    products_str += f"\n  Mots-clés: {keywords}"
                if sectors:
                    products_str += f"\n  Secteurs: {sectors}"
                products_str += "\n"
        else:
            products_str = "Aucun produit disponible."
        
        deadline_str = request.deadline or "Non précisée"
        
        user_prompt = f"""{user_prompt_template}

## Marché à évaluer

- **Titre**: {request.title}
- **Acheteur**: {request.buyer}
- **Montant**: {request.amount}
- **Deadline**: {deadline_str}
- **Description**: {request.description[:2000] if request.description else 'Non disponible'}

## Produits Cadoles disponibles

{products_str}
"""
        
        messages = [
            {"role": "system", "content": role_prompt},
            {"role": "user", "content": user_prompt}
        ]
        
        client = httpx.AsyncClient(timeout=120.0)
        try:
            response = await client.post(
                f"{config['base_url']}/chat/completions",
                headers={
                    "Authorization": f"Bearer {config['api_key']}",
                    "Content-Type": "application/json"
                },
                json={
                    "model": config["model"],
                    "messages": messages
                }
            )
            response.raise_for_status()
            data = response.json()
            
        except Exception as e:
            await client.aclose()
            save_job(job_id, {"status": "error", "result": {"error": f"Erreur de connexion à l'API: {str(e)}"}})
            return
        
        await client.aclose()
        
        choice = data["choices"][0]
        message_content = choice["message"]
        content = message_content.get("content", "")
        
        json_match = None
        try:
            decoder = json.JSONDecoder()
            json_match, end_idx = decoder.raw_decode(content)
        except (json.JSONDecodeError, ValueError):
            for pattern in ["```json", "```"]:
                start = content.find(pattern)
                if start != -1:
                    start += len(pattern)
                    end = content.find("```", start)
                    if end != -1:
                        json_str = content[start:end].strip()
                        try:
                            json_match = json.loads(json_str)
                            break
                        except json.JSONDecodeError:
                            pass
        
        if json_match:
            save_job(job_id, {
                "status": "done",
                "result": {
                    "score": json_match.get("score", 0),
                    "priority": json_match.get("priority", "C"),
                    "explanation": json_match.get("explanation", ""),
                    "products": json_match.get("products", []),
                    "tools_used": []
                }
            })
        else:
            save_job(job_id, {
                "status": "error",
                "result": {
                    "error": "Impossible de parser la réponse JSON",
                    "raw_response": content[:500],
                    "tools_used": []
                }
            })
        
    except Exception as e:
        save_job(job_id, {"status": "error", "result": {"error": f"Erreur inattendue: {str(e)}"}})


async def process_report(job_id: str, request: ReportRequest) -> None:
    """Process report generation in background and save result to job file."""
    save_job(job_id, {"status": "pending", "result": None})

    try:
        config = get_ai_config()

        system_prompt = load_report_system_prompt()
        user_prompt_template = load_report_user_prompt()

        metadata = (
            f"- ID: {request.market_id}\n"
            f"- Titre: {request.title}\n"
            f"- Acheteur: {request.buyer or 'N/A'}\n"
            f"- Montant: {request.amount or 'N/A'}\n"
            f"- Deadline: {request.deadline or 'Non précisée'}"
        )

        products_str = ""
        if request.products:
            for p in request.products:
                name = p.get("name", "Unknown")
                desc = p.get("description", "")
                keywords = p.get("keywords", "")
                sectors = p.get("sectors", "")
                products_str += f"- **{name}**"
                if desc:
                    products_str += f"\n  Description: {desc[:200]}"
                if keywords:
                    products_str += f"\n  Mots-clés: {keywords}"
                if sectors:
                    products_str += f"\n  Secteurs: {sectors}"
                products_str += "\n"
        else:
            products_str = "Aucun produit identifié par le scoring."

        chat_history_str = ""
        if request.chat_history:
            for msg in request.chat_history:
                role = msg.get("role", "user")
                content = msg.get("content", "")
                label = "Utilisateur" if role == "user" else "Assistant"
                chat_history_str += f"- **{label}**: {content[:1000]}\n"
        else:
            chat_history_str = "Aucun message marqué comme important."

        user_prompt = (
            user_prompt_template
            .replace("{{metadata}}", metadata)
            .replace("{{description}}", request.description[:4000] if request.description else "Non disponible")
            .replace("{{products}}", products_str)
            .replace("{{catalogue}}", request.catalogue or "Catalogue non fourni.")
            .replace("{{chatHistory}}", chat_history_str)
        )

        messages = [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ]

        client = httpx.AsyncClient(timeout=180.0)
        tools_used = []

        try:
            response = await client.post(
                f"{config['base_url']}/chat/completions",
                headers={
                    "Authorization": f"Bearer {config['api_key']}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": config["model"],
                    "messages": messages,
                    "tools": get_tools(),
                    "tool_choice": "auto",
                },
            )
            response.raise_for_status()
            data = response.json()

        except Exception as e:
            await client.aclose()
            save_job(job_id, {
                "status": "error",
                "result": {"error": f"Erreur de connexion à l'API: {str(e)}"},
            })
            return

        choice = data["choices"][0]
        message_content = choice["message"]

        while message_content.get("tool_calls"):
            for tool_call in message_content["tool_calls"]:
                tool_name = tool_call["function"]["name"]
                arguments = json.loads(tool_call["function"]["arguments"])
                tools_used.append(tool_name)

                tool_result = await execute_tool(tool_name, arguments)

                messages.append({
                    "role": "tool",
                    "tool_call_id": tool_call["id"],
                    "content": tool_result,
                })

            try:
                response = await client.post(
                    f"{config['base_url']}/chat/completions",
                    headers={
                        "Authorization": f"Bearer {config['api_key']}",
                        "Content-Type": "application/json",
                    },
                    json={
                        "model": config["model"],
                        "messages": messages,
                        "tools": get_tools(),
                        "tool_choice": "auto",
                    },
                )
                response.raise_for_status()
                data = response.json()
                choice = data["choices"][0]
                message_content = choice["message"]
            except Exception as e:
                await client.aclose()
                save_job(job_id, {
                    "status": "error",
                    "result": {
                        "error": f"Erreur lors de l'exécution des outils: {str(e)}",
                        "tools_used": tools_used,
                    },
                })
                return

        await client.aclose()

        markdown = message_content.get("content", "").strip()

        if not markdown:
            save_job(job_id, {
                "status": "error",
                "result": {"error": "Réponse vide de l'agent", "tools_used": tools_used},
            })
            return

        save_job(job_id, {
            "status": "done",
            "result": {
                "markdown": markdown,
                "tools_used": tools_used,
            },
        })

    except Exception as e:
        save_job(job_id, {
            "status": "error",
            "result": {"error": f"Erreur inattendue: {str(e)}"},
        })


@app.get("/health")
async def health():
    return {"status": "ok", "service": "agent"}


@app.post("/chat")
async def chat(request: ChatRequest):
    """Start a chat job - uses subprocess to avoid async issues."""
    import subprocess
    import sys
    import json
    import os
    cleanup_old_jobs()
    
    job_id = str(uuid.uuid4())
    save_job(job_id, {"status": "pending", "result": None})
    
    request_data = {
        "market_id": request.market_id,
        "message": request.message,
        "history": request.history,
        "reset_session": request.reset_session
    }
    
    log_file = f"/tmp/jobs/{job_id}.log"
    
    with open(log_file, "w") as f:
        f.write(f"Starting chat job {job_id}\n")
    
    subprocess.Popen(
        [sys.executable, "/app/agent/run_job.py", "chat", job_id, json.dumps(request_data)],
        stdout=open(log_file, "a"),
        stderr=subprocess.STDOUT,
        start_new_session=True
    )
    
    return {"job_id": job_id}


@app.get("/chat/result/{job_id}")
async def get_chat_result(job_id: str):
    """Get the result of a chat job."""
    job = load_job(job_id)
    
    if not job:
        return {"status": "not_found", "result": None}
    
    return job


@app.post("/score")
async def score_market(request: ScoreRequest):
    """Start a scoring job - uses subprocess to avoid async issues."""
    import subprocess
    import sys
    import json
    import os
    cleanup_old_jobs()
    
    job_id = str(uuid.uuid4())
    save_job(job_id, {"status": "pending", "result": None})
    
    request_data = {
        "market_id": request.market_id,
        "title": request.title,
        "buyer": request.buyer,
        "description": request.description,
        "amount": request.amount,
        "deadline": request.deadline,
        "products": request.products
    }
    
    log_file = f"/tmp/jobs/{job_id}.log"
    
    with open(log_file, "w") as f:
        f.write(f"Starting score job {job_id}\n")
        f.write(f"Request: {json.dumps(request_data)[:200]}\n")
    
    subprocess.Popen(
        [sys.executable, "/app/agent/run_job.py", "score", job_id, json.dumps(request_data)],
        stdout=open(log_file, "a"),
        stderr=subprocess.STDOUT,
        start_new_session=True
    )
    
    return {"job_id": job_id}


@app.get("/score/result/{job_id}")
async def get_score_result(job_id: str):
    """Get the result of a scoring job."""
    job = load_job(job_id)

    if not job:
        return {"status": "not_found", "result": None}

    return job


@app.post("/report")
async def report_market(request: ReportRequest):
    """Start a report generation job - uses subprocess to avoid async issues."""
    import subprocess
    import sys
    cleanup_old_jobs()

    job_id = str(uuid.uuid4())
    save_job(job_id, {"status": "pending", "result": None})

    request_data = {
        "market_id": request.market_id,
        "title": request.title,
        "buyer": request.buyer,
        "description": request.description,
        "amount": request.amount,
        "deadline": request.deadline,
        "products": request.products,
        "catalogue": request.catalogue,
        "chat_history": request.chat_history,
    }

    log_file = f"/tmp/jobs/{job_id}.log"

    with open(log_file, "w") as f:
        f.write(f"Starting report job {job_id}\n")
        f.write(f"Request: {json.dumps({k: v if k != 'description' else v[:100] for k, v in request_data.items()})}\n")

    subprocess.Popen(
        [sys.executable, "/app/agent/run_job.py", "report", job_id, json.dumps(request_data)],
        stdout=open(log_file, "a"),
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )

    return {"job_id": job_id}


@app.get("/report/result/{job_id}")
async def get_report_result(job_id: str):
    """Get the result of a report job."""
    job = load_job(job_id)

    if not job:
        return {"status": "not_found", "result": None}

    return job


@app.delete("/chat/{job_id}")
async def delete_job(job_id: str):
    """Delete a job file."""
    job_file = get_job_file(job_id)
    if os.path.exists(job_file):
        os.remove(job_file)
    return {"deleted": True}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)
