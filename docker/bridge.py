from pathlib import Path
from v3_engine.local_env import load_local_env
load_local_env(Path('/app/.env'))
import uvicorn
uvicorn.run('v3_engine.bridge:app', host='0.0.0.0', port=8765)
