#!/usr/bin/env python3
"""
Runner script for background jobs.
Usage: python3 run_job.py <job_type> <job_id> <json_request>
"""
import sys
import json
import asyncio

sys.path.insert(0, '/app')

from agent.main import process_score, process_chat, ScoreRequest, ChatRequest

def main():
    if len(sys.argv) < 4:
        print("Usage: run_job.py <job_type> <job_id> <json_request>", file=sys.stderr)
        sys.exit(1)
    
    job_type = sys.argv[1]
    job_id = sys.argv[2]
    request_json = json.loads(sys.argv[3])
    
    print(f"Starting {job_type} job {job_id}", flush=True)
    print(f"Request: {json.dumps(request_json)[:200]}...", flush=True)
    
    try:
        if job_type == "score":
            request = ScoreRequest(**request_json)
            asyncio.run(process_score(job_id, request))
        elif job_type == "chat":
            request = ChatRequest(**request_json)
            asyncio.run(process_chat(job_id, request.market_id, request.message, request.history))
        else:
            print(f"Unknown job type: {job_type}", file=sys.stderr)
            sys.exit(1)
        
        print(f"Job {job_id} completed successfully", flush=True)
    except Exception as e:
        print(f"Job {job_id} failed: {e}", flush=True)
        import traceback
        traceback.print_exc()
        sys.exit(1)

if __name__ == "__main__":
    main()
