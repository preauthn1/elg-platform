class LuckyDrawIssuesDetailSummary {
  final String totalCountTickets;
  final String totalCountUnclaimed;
  final String totalCountWinnings;
  LuckyDrawIssuesDetailSummary({
    this.totalCountTickets,
    this.totalCountUnclaimed,
    this.totalCountWinnings,
  });
}

class LuckyDrawIssuesDetail {
  final String beginTime;
  final String endTime;
  final String issueId;
  final String issueName;
  final String resultNumber;
  final LuckyDrawIssuesDetailSummary summary;
  LuckyDrawIssuesDetail({
    this.beginTime,
    this.endTime,
    this.issueId,
    this.issueName,
    this.resultNumber,
    this.summary,
  });
}
