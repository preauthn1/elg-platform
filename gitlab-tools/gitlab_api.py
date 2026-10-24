"""Small, dependency-free helpers for GitLab GraphQL snapshot jobs."""

from __future__ import annotations

import json
import os
import tempfile
import time
import urllib.error
import urllib.request


PAGE_SIZE = 100
MAX_PAGES = 100
RETRYABLE_HTTP_CODES = {429, 502, 503, 504}


class GitLabGraphQLError(RuntimeError):
    """Raised when GitLab cannot return a complete, trustworthy snapshot."""


def _retry_delay(error: urllib.error.HTTPError, attempt: int) -> float:
    retry_after = error.headers.get("Retry-After") if error.headers else None
    try:
        return min(30.0, max(0.0, float(retry_after)))
    except (TypeError, ValueError):
        return float(2**attempt)


def _post_graphql(server, token, query, variables, opener, sleep, retries, timeout):
    body = json.dumps({"query": query, "variables": variables}).encode("utf-8")
    request = urllib.request.Request(
        server.rstrip("/") + "/api/graphql",
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Authorization": "Bearer " + token,
        },
    )

    for attempt in range(retries + 1):
        try:
            with opener(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            if error.code not in RETRYABLE_HTTP_CODES or attempt == retries:
                detail = error.read().decode("utf-8", errors="replace")[:300]
                raise GitLabGraphQLError(
                    "GraphQL HTTP %s: %s" % (error.code, detail)
                ) from error
            sleep(_retry_delay(error, attempt))
        except urllib.error.URLError as error:
            if attempt == retries:
                raise GitLabGraphQLError("GraphQL network error: %s" % error) from error
            sleep(float(2**attempt))
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise GitLabGraphQLError("GraphQL returned invalid JSON") from error


def fetch_all_work_items(
    server,
    project,
    token,
    fields,
    *,
    opener=urllib.request.urlopen,
    sleep=time.sleep,
    retries=2,
    timeout=30,
    max_pages=MAX_PAGES,
    log=print,
):
    """Fetch every opened work item using GitLab Relay cursor pagination."""
    query = """
query($project: ID!, $after: String) {
  project(fullPath: $project) {
    workItems(state: opened, first: %d, after: $after) {
      nodes { %s }
      pageInfo { hasNextPage endCursor }
    }
  }
}
""" % (PAGE_SIZE, fields)

    nodes = []
    seen_iids = set()
    seen_cursors = set()
    cursor = None

    for page in range(1, max_pages + 1):
        payload = _post_graphql(
            server,
            token,
            query,
            {"project": project, "after": cursor},
            opener,
            sleep,
            retries,
            timeout,
        )
        if payload.get("errors"):
            detail = json.dumps(payload["errors"], ensure_ascii=False)[:500]
            raise GitLabGraphQLError("GraphQL errors: " + detail)

        project_data = (payload.get("data") or {}).get("project")
        if project_data is None:
            raise GitLabGraphQLError(
                "Project not found or token has no access: " + project
            )
        connection = project_data.get("workItems")
        if not isinstance(connection, dict):
            raise GitLabGraphQLError("GraphQL response is missing workItems")

        page_nodes = connection.get("nodes")
        page_info = connection.get("pageInfo")
        if not isinstance(page_nodes, list) or not isinstance(page_info, dict):
            raise GitLabGraphQLError("GraphQL response has invalid pagination data")

        for node in page_nodes:
            iid = str(node.get("iid", ""))
            if not iid:
                raise GitLabGraphQLError("Work item is missing iid")
            if iid in seen_iids:
                raise GitLabGraphQLError("Duplicate work item across pages: " + iid)
            seen_iids.add(iid)
            nodes.append(node)

        has_next = page_info.get("hasNextPage")
        if not isinstance(has_next, bool):
            raise GitLabGraphQLError("pageInfo.hasNextPage is missing or invalid")
        log("GraphQL page %d: %d items (total %d)" % (page, len(page_nodes), len(nodes)))
        if not has_next:
            return nodes

        next_cursor = page_info.get("endCursor")
        if not next_cursor:
            raise GitLabGraphQLError("hasNextPage is true but endCursor is missing")
        if next_cursor == cursor or next_cursor in seen_cursors:
            raise GitLabGraphQLError("GraphQL cursor did not advance")
        seen_cursors.add(next_cursor)
        cursor = next_cursor

    raise GitLabGraphQLError(
        "Pagination exceeded %d pages; refusing to publish partial data" % max_pages
    )


def json_for_html(value):
    """Serialize JSON safely for embedding inside an HTML script element."""
    return (
        json.dumps(value, ensure_ascii=False, separators=(",", ":"))
        .replace("&", "\\u0026")
        .replace("<", "\\u003c")
        .replace(">", "\\u003e")
        .replace("\u2028", "\\u2028")
        .replace("\u2029", "\\u2029")
    )


def atomic_write_text(path, content):
    """Replace a generated file only after the complete content is ready."""
    directory = os.path.dirname(path) or "."
    os.makedirs(directory, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(
            "w", encoding="utf-8", dir=directory, delete=False
        ) as handle:
            temporary = handle.name
            handle.write(content)
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    finally:
        if temporary and os.path.exists(temporary):
            os.unlink(temporary)
