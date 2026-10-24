import unittest
from unittest.mock import patch

import generate_board
import generate_op


def assignee_widget(*people):
    return {
        "assignees": {
            "nodes": [
                {
                    "username": username,
                    "name": name,
                    "avatarUrl": "https://gitlab.example/avatar/%s.png" % username,
                }
                for username, name in people
            ]
        }
    }


class BoardRowsTests(unittest.TestCase):
    def test_keeps_each_assignee_identity(self):
        rows = generate_board.to_rows(
            [
                {
                    "iid": "7",
                    "title": "[GFF][API] task",
                    "widgets": [
                        assignee_widget(("tim", "Tim"), ("ben", "Ben")),
                        {"startDate": "2026-01-01", "dueDate": "2026-01-02"},
                    ],
                }
            ]
        )
        self.assertEqual(
            [
                {
                    "username": "tim",
                    "name": "Tim",
                    "avatar": "https://gitlab.example/avatar/tim.png",
                },
                {
                    "username": "ben",
                    "name": "Ben",
                    "avatar": "https://gitlab.example/avatar/ben.png",
                },
            ],
            rows[0][1],
        )
        self.assertEqual("[API] task", rows[0][4])

    def test_template_namespaces_member_keys_and_avoids_object_prototypes(self):
        self.assertIn('function memberKey(p){return "u:"+', generate_board.TEMPLATE)
        self.assertIn("members=Object.create(null)", generate_board.TEMPLATE)
        self.assertIn("projs=Object.create(null)", generate_board.TEMPLATE)
        self.assertIn('"Max":"開發"', generate_board.TEMPLATE)
        self.assertIn("r.total>=OVERLOAD_TASKS", generate_board.TEMPLATE)

    def test_template_has_calendar_views_and_unscheduled_work(self):
        self.assertIn('id="calendarPanel"', generate_board.TEMPLATE)
        self.assertIn('data-m="month"', generate_board.TEMPLATE)
        self.assertIn('data-m="week"', generate_board.TEMPLATE)
        self.assertIn("function weekSegments", generate_board.TEMPLATE)
        self.assertIn("function calendarPeople", generate_board.TEMPLATE)
        self.assertIn('${calendarPeople(s.it.people)}<span class="cal-event-title">', generate_board.TEMPLATE)
        self.assertIn("function memberColor", generate_board.TEMPLATE)
        self.assertIn("function eventBackground", generate_board.TEMPLATE)
        self.assertIn('id="calPersonLegend"', generate_board.TEMPLATE)
        self.assertIn("const HOLIDAYS = __HOLIDAYS__", generate_board.TEMPLATE)
        self.assertIn("cal-day-swatch holiday", generate_board.TEMPLATE)
        self.assertIn("avatarUrl", generate_board.FIELDS)
        self.assertIn("!it.s&&!it.due", generate_board.TEMPLATE)


class OpRowsTests(unittest.TestCase):
    @patch("generate_op.fetch")
    def test_keeps_each_assignee_identity(self, fetch):
        fetch.return_value = [
            {
                "iid": "8",
                "title": "[SITE] incident",
                "createdAt": "2026-01-01T00:00:00Z",
                "updatedAt": "2026-01-02T00:00:00Z",
                "widgets": [
                    assignee_widget(("tim", "Tim"), ("ben", "Ben")),
                    {"labels": {"nodes": [{"title": ".P1"}]}},
                ],
            }
        ]
        result = generate_op.rows()[0]
        self.assertEqual("P1", result["pri"])
        self.assertEqual("SITE", result["pf"])
        self.assertEqual(2, len(result["assignees"]))

    def test_template_avoids_object_prototypes(self):
        self.assertIn("m=Object.create(null)", generate_op.TEMPLATE)
        self.assertIn('k="u:"+', generate_op.TEMPLATE)


if __name__ == "__main__":
    unittest.main()
