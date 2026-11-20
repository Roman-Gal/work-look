"""One bounded GET per selected endpoint; no HTML job extraction or retries.

Research utility only. The proposed application backend remains PHP/Symfony.
Requires Python 3.10+ and curl on PATH. Evidence contains schema, not job texts.
"""

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parent
TARGETS = {
    "justjoin": "https://justjoin.it/api/candidate-api/offers?page=1&perPage=1",
    "nofluff-list": "https://nofluffjobs.com/api/posting",
    "nofluff-search-get": "https://nofluffjobs.com/api/search/posting",
    "dou": "https://jobs.dou.ua/vacancies/feeds/?category=PHP",
    "djinni": "https://djinni.co/jobs/rss/?primary_keyword=PHP",
    "arbeitnow": "https://www.arbeitnow.com/api/job-board-api?page=1",
    "remotive": "https://remotive.com/api/remote-jobs?search=php&limit=1",
    "greenhouse": "https://boards-api.greenhouse.io/v1/boards/stripe/jobs",
    "lever": "https://api.lever.co/v0/postings/spotify?mode=json&limit=1",
    "ashby": "https://api.ashbyhq.com/posting-api/job-board/ashby?includeCompensation=true",
    "robota": "https://api.robota.ua/vacancy/search?keyWords=php&count=1&page=0",
    "justjoin-limit": "https://justjoin.it/api/candidate-api/offers?limit=1",
    "solidjobs": "https://solid.jobs/public-api/offers/IT?campaign=jobfind-local-research&pageSize=1&pageIndex=0",
    "solidjobs-page1": "https://solid.jobs/public-api/offers/IT?campaign=jobfind-local-research&pageSize=1&pageIndex=1",
    "solidjobs-php": "https://solid.jobs/public-api/offers/IT?campaign=jobfind-local-research&pageSize=1&pageIndex=0&search.searchTerm=PHP",
    "solidjobs-rss": "https://solid.jobs/rss/job-offers/subcategory/PHP",
    "nofluff-detail": "https://nofluffjobs.com/api/posting/software-engineer-big-data-adform-warszawa-1",
    "robota-detail": "https://api.robota.ua/vacancy?id=11386804",
    "justjoin-detail": "https://justjoin.it/api/candidate-api/offers/strada-sp-z-o-o--specjalista-specjalistka-ds-wsparcia-it-mierzecice-support",
}


def shape(value, depth=0):
    if depth >= 5:
        return type(value).__name__
    if isinstance(value, dict):
        return {key: shape(item, depth + 1) for key, item in value.items()}
    if isinstance(value, list):
        return {"type": "array", "length": len(value),
                "first_item_shape": shape(value[0], depth + 1) if value else None}
    return type(value).__name__


def probe(source, max_bytes=5000000):
    url = TARGETS[source]
    result = {"source": source, "url": url, "method": "GET",
              "checked_at_utc": datetime.now(timezone.utc).isoformat(),
              "user_agent": "JOBFIND-Research/0.1 (one-shot endpoint verification)"}
    with tempfile.TemporaryDirectory(prefix="jobfind-probe-") as temp:
        body_file = Path(temp) / "body"
        header_file = Path(temp) / "headers"
        command = [
            "curl", "--silent", "--show-error", "--proto", "=https",
            "--connect-timeout", "8", "--max-time", "25",
            "--max-filesize", str(max_bytes), "--compressed",
            "--user-agent", result["user_agent"],
            "--header", "Accept: application/json, application/rss+xml, application/xml;q=0.9",
            "--dump-header", str(header_file), "--output", str(body_file),
            "--write-out", "%{json}", url,
        ]
        try:
            response = subprocess.run(command, capture_output=True, timeout=30)
            result["curl_exit_code"] = response.returncode
            if response.stderr:
                result["transport_message"] = response.stderr.decode("utf-8", "replace").strip()
            try:
                metadata = json.loads(response.stdout)
                for key in ("http_code", "content_type", "size_download", "time_total", "url_effective"):
                    result[key] = metadata.get(key)
            except (ValueError, UnicodeDecodeError):
                result["metadata_error"] = "curl did not return valid metadata"
            headers = {}
            if header_file.exists():
                for line in header_file.read_text(encoding="utf-8", errors="replace").splitlines():
                    name, sep, value = line.partition(":")
                    if sep and name.lower() in {"etag", "last-modified", "retry-after", "location", "allow", "cache-control"}:
                        headers[name.lower()] = value.strip()
            result["selected_headers"] = headers
            body = body_file.read_bytes() if body_file.exists() else b""
            result["body_bytes"] = len(body)
            result["body_sha256"] = hashlib.sha256(body).hexdigest()
            result["payload_kind"] = "empty" if not body else "non_structured_or_unparsed"
            try:
                data = json.loads(body)
                result["payload_kind"] = "json"
                result["shape"] = shape(data)
                if isinstance(data, dict):
                    result["scalar_metadata"] = {k: v for k, v in data.items()
                                                 if k in {"total", "totalCount", "count", "page", "pages", "perPage", "pageSize", "pageIndex", "totalPages", "job-count", "total-job-count"}
                                                 and isinstance(v, (int, float, bool, type(None)))}
                    for key in ("meta", "links"):
                        if isinstance(data.get(key), dict):
                            result[key] = {k: v for k, v in data[key].items()
                                           if isinstance(v, (int, float, bool, type(None)))
                                           or isinstance(v, str) and v.startswith("https://")}
                items = []
                if isinstance(data, list):
                    items = data
                elif isinstance(data, dict):
                    items = next((data[key] for key in ("data", "postings", "jobs", "documents", "items")
                                  if isinstance(data.get(key), list)), [])
                if items and isinstance(items[0], dict):
                    result["sample_identifiers"] = {key: value for key, value in items[0].items()
                                                    if key in {"id", "guid", "slug", "jobOfferKey"}
                                                    and isinstance(value, (int, str))}
            except (ValueError, UnicodeDecodeError):
                if len(body) <= max_bytes and b"<!DOCTYPE" not in body.upper() and b"<!ENTITY" not in body.upper():
                    try:
                        root = ET.fromstring(body)
                        result["xml_root"] = root.tag
                        items = root.findall("./channel/item")
                        if root.tag == "rss":
                            result["payload_kind"] = "rss"
                            result["item_count"] = len(items)
                            result["first_item_fields"] = [child.tag for child in items[0]] if items else []
                            if items:
                                result["description_chars"] = len(items[0].findtext("description", ""))
                    except ET.ParseError:
                        pass
            result["structured_success"] = (
                response.returncode == 0 and result.get("http_code") == 200
                and result["payload_kind"] in {"json", "rss"}
            )
        except (OSError, subprocess.TimeoutExpired) as error:
            result["transport_message"] = str(error)
            result["structured_success"] = False
    evidence = ROOT / "evidence"
    evidence.mkdir(exist_ok=True)
    target = evidence / f"{source}.json"
    target.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({k: result.get(k) for k in
                      ("source", "http_code", "payload_kind", "body_bytes", "structured_success", "transport_message")},
                     ensure_ascii=True))
    return result["structured_success"]


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("sources", nargs="+", choices=sorted(TARGETS), help="Explicit sources; no default full crawl")
    parser.add_argument("--max-bytes", type=int, default=5000000, help="Explicit decoded response-size ceiling")
    args = parser.parse_args()
    if not 1 <= args.max_bytes <= 20000000:
        parser.error("--max-bytes must be between 1 and 20000000")
    outcomes = [probe(source, args.max_bytes) for source in args.sources]
    raise SystemExit(0 if all(outcomes) else 1)
