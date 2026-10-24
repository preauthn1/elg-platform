class LuckyDrawTickets {
  final String resultNumber;
  final List<Tickets> tickets;
  final Pagination pagination;
  LuckyDrawTickets({this.resultNumber, this.tickets, this.pagination});
}

class Tickets {
  final String ticketId;
  final String rewardAmount;
  final String ticketNumber;
  final String rewardLevel;
  final String rewardType;//1:獎金, 2:獎品
  final String rewardName;
  final String status;
  final String statusText;
  Tickets({
    this.ticketId,
    this.rewardAmount,
    this.ticketNumber,
    this.rewardLevel,
    this.rewardType,
    this.rewardName,
    this.status,
    this.statusText,
  });
}

class Pagination {
  final String current;
  final String next;
  final String pages;
  final String perPage;
  final String previous;
  final String total;
  Pagination({
    this.current,
    this.next,
    this.pages,
    this.perPage,
    this.previous,
    this.total,
  });
}
