import unittest

from tw_holidays import TW_HOLIDAYS


class TaiwanHolidayTests(unittest.TestCase):
    def test_official_calendar_years_are_present(self):
        years = {date[:4] for date in TW_HOLIDAYS}
        self.assertEqual({"2025", "2026", "2027"}, years)

    def test_named_and_makeup_holidays_are_present(self):
        self.assertEqual("中秋節", TW_HOLIDAYS["2026-09-25"])
        self.assertEqual("補假", TW_HOLIDAYS["2026-10-26"])
        self.assertEqual("春節", TW_HOLIDAYS["2027-02-06"])

    def test_ordinary_weekends_are_not_duplicated(self):
        self.assertNotIn("2026-09-19", TW_HOLIDAYS)


if __name__ == "__main__":
    unittest.main()
