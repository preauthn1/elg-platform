import io
import json
import os
import stat
import tempfile
import unittest
import urllib.error

from gitlab_api import (
    GitLabGraphQLError,
    atomic_write_text,
    fetch_all_work_items,
    json_for_html,
)


class FakeResponse:
    def __init__(self, payload):
        self.payload = payload

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def read(self):
        return json.dumps(self.payload).encode()


def page(nodes, has_next=False, cursor=None):
    return {
        "data": {
            "project": {
                "workItems": {
                    "nodes": nodes,
                    "pageInfo": {"hasNextPage": has_next, "endCursor": cursor},
                }
            }
        }
    }


class PaginationTests(unittest.TestCase):
    def fetch(self, payloads, **kwargs):
        calls = []

        def opener(request, timeout):
            calls.append(json.loads(request.data)["variables"])
            payload = payloads.pop(0)
            if isinstance(payload, Exception):
                raise payload
            return FakeResponse(payload)

        result = fetch_all_work_items(
            "https://gitlab.example",
            "group/project",
            "secret",
            "iid title",
            opener=opener,
            sleep=lambda _seconds: None,
            log=lambda _message: None,
            **kwargs,
        )
        return result, calls

    def test_single_page(self):
        result, calls = self.fetch([page([{"iid": "1"}])])
        self.assertEqual([{"iid": "1"}], result)
        self.assertEqual([{"project": "group/project", "after": None}], calls)

    def test_fetches_all_pages(self):
        first = [{"iid": str(i)} for i in range(1, 101)]
        second = [{"iid": str(i)} for i in range(101, 110)]
        result, calls = self.fetch(
            [page(first, True, "cursor-1"), page(second, False, "cursor-2")]
        )
        self.assertEqual(109, len(result))
        self.assertEqual("cursor-1", calls[1]["after"])

    def test_graphql_errors_fail(self):
        with self.assertRaisesRegex(GitLabGraphQLError, "GraphQL errors"):
            self.fetch([{"errors": [{"message": "denied"}]}])

    def test_missing_cursor_fails(self):
        with self.assertRaisesRegex(GitLabGraphQLError, "endCursor is missing"):
            self.fetch([page([], True, None)])

    def test_missing_has_next_page_fails(self):
        payload = page([])
        del payload["data"]["project"]["workItems"]["pageInfo"]["hasNextPage"]
        with self.assertRaisesRegex(GitLabGraphQLError, "hasNextPage"):
            self.fetch([payload])

    def test_repeated_cursor_fails(self):
        with self.assertRaisesRegex(GitLabGraphQLError, "cursor did not advance"):
            self.fetch([page([], True, "same"), page([], True, "same")])

    def test_duplicate_iid_fails(self):
        with self.assertRaisesRegex(GitLabGraphQLError, "Duplicate work item"):
            self.fetch(
                [page([{"iid": "7"}], True, "next"), page([{"iid": "7"}])]
            )

    def test_page_limit_fails_instead_of_truncating(self):
        with self.assertRaisesRegex(GitLabGraphQLError, "refusing to publish"):
            self.fetch([page([], True, "next")], max_pages=1)

    def test_retryable_http_error_is_retried(self):
        error = urllib.error.HTTPError(
            "url", 503, "unavailable", {}, io.BytesIO(b"try later")
        )
        result, calls = self.fetch([error, page([{"iid": "1"}])])
        self.assertEqual(1, len(result))
        self.assertEqual(2, len(calls))


class HtmlJsonTests(unittest.TestCase):
    def test_script_breakout_characters_are_escaped(self):
        encoded = json_for_html({"title": "</script>&\u2028"})
        self.assertNotIn("</script>", encoded)
        self.assertNotIn("&", encoded)
        self.assertIn("\\u003c/script\\u003e", encoded)
        self.assertIn("\\u2028", encoded)


class AtomicWriteTests(unittest.TestCase):
    def test_output_is_world_readable_and_replaced(self):
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, "index.html")
            atomic_write_text(path, "first")
            atomic_write_text(path, "second")
            with open(path, encoding="utf-8") as handle:
                self.assertEqual("second", handle.read())
            self.assertEqual(0o644, stat.S_IMODE(os.stat(path).st_mode))


if __name__ == "__main__":
    unittest.main()
