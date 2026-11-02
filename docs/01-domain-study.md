# DineReserve — Domain Study Plan and Interview Record

**Document:** `docs/01-domain-study.md` · **Owner:** Stream D (documentation) · **Group:** 6
**Status (2026-11-02, W6):** protocol final; field records pending — the five interview rows and
the two observation rows below must be filled from the original transcripts before submission.
**Related artefacts:** [architecture](02-architecture.md) · [traceability](05-traceability.md) ·
[context diagram](diagrams/context.svg) · [booking flow](diagrams/booking-flow.svg) · Task 2 URS.

> **Integrity rule.** This file contains **no invented quotes, restaurant names, dates or counts**.
> The URS states that five local restaurant owners and hosts were interviewed during the domain
> study (URS §1.7, p.7) and that two observation sessions of the partner restaurant were planned
> (URS §4, p.9). The protocol below documents how that evidence was collected; every
> restaurant-level row is left as an explicit team-fill placeholder.
> A placeholder looks like this: `<!-- TODO(团队填写): ... -->`. The closing checklist lists all
> of them.

---

## 1. Purpose and scope

The domain study exists to turn the six problems in the Task 1 proposal into verifiable user
requirements. Its three jobs are:

1. **Validate the problem** — confirm with restaurant staff that phone overload, manual table
   allocation, no-shows, late cancellations, missing operating data and platform lock-in are real
   and material, and find out which of them hurts most.
2. **Capture current practice** — how a reservation is taken, recorded, allocated, confirmed,
   changed and closed today, including the workarounds (paper book, sticky notes, WhatsApp,
   personal mobile phones).
3. **Size the baseline** — obtain defensible baseline values for the proposal success metrics
   (no-show rate, share of online bookings, time to take a booking, covers recovered from
   cancellations) so that the pilot can later be compared against a real "before" number.

Out of scope: pricing design, menu/food operations, card payments and deposits, and any
collection of diner personal data. Availability, booking, change/cancel, notification, waitlist,
floor plan, reporting and risk scoring are in scope because they are the nine functional
requirements (REQ-1.1 – REQ-1.9) in URS §5.

**Sources of evidence (triangulation).** URS §1.7 names three elicitation sources: (a) the
assigned customer team, (b) five local restaurant owners/hosts interviewed during the domain
study, and (c) team members acting as diners. This plan covers source (b) plus the two
observation sessions required by URS §4.

---

## 2. Method

### 2.1 Semi-structured interviews (five SMB restaurants)

| Item | Decision |
|---|---|
| Sampling | Purposive/convenience sample of five independent small or medium restaurants in Macau (Taipa / Coloane / peninsula), selected to cover different service styles and both lunch- and dinner-led businesses. |
| Unit of analysis | The restaurant as a reservation operation; the individual as a role holder. |
| Respondents per restaurant | Owner/manager **or** front-desk supervisor (at least one of the two), plus optionally one regular diner per restaurant for 2–3 of the five. |
| Duration / language | 30–45 minutes, semi-structured, conducted in Cantonese, Mandarin or English according to the respondent; the interviewer may paraphrase questions but must not lead the answer. |
| Recording | Audio recording only with written consent; otherwise two interviewers take independent notes. Every transcript/audio file gets an ID (`R1`…`R5`) that is stored separately from the anonymised name. |
| Anonymisation | Each restaurant gets a pseudonym (`R1`…`R5`) before analysis. Real names, addresses, phone numbers and staff names never appear in this file or in the repository. Quotes are attributed only to a role ("owner of R3"), never to a person. |
| Ethics | Informed consent read and signed before recording; respondents may skip any question or stop at any time; no diner PII is collected; recordings/transcripts are deleted after the course assessment and are never committed to GitHub. |

**Interview procedure (60 minutes total):**

1. **Consent and framing (5 min)** — purpose, anonymity, no sales pitch, right to withdraw.
2. **Warm-up (5 min)** — role, years in business, covers per service, how reservations arrive.
3. **Current process walkthrough (20 min)** — questions 1–5 of the guide below, following one real
   service from first contact to table close-out.
4. **Pain and workarounds (15 min)** — questions 6–9, asking for a concrete last occurrence
   ("when was the last time…?") rather than an opinion.
5. **Adoption constraints and close (10 min)** — question 10; consent to be contacted for a
   follow-up / member check; ask for the two observation sessions.
6. **Within 24 hours** — transcript or expanded notes; send the anonymised summary back to the
   respondent for a member check (correction of facts, not of opinion).

**Coding and analysis.** Open coding of transcripts → cluster codes into the six proposal
problems → keep only pain points reported by at least two independent restaurants or observed
twice → map each surviving pain point to one or more REQ IDs (Section 5) → record disconfirming
evidence as well (for example a restaurant that says no-shows are already under control) so that
the URS assumptions can be challenged.

### 2.2 Two on-site observation sessions

Observation complements what people *say* with what the front desk actually *does* during a
service. The two sessions follow URS §4: one observation during requirements and one pilot
service during acceptance.

| Session | Planned window | Focus |
|---|---|---|
| O1 — requirements observation | One Friday dinner service, 17:30–22:00 | Peak-load behaviour: phone calls, double-booking recovery, walk-ins, paper-book edits, no-show handling. |
| O2 — requirements observation | One weekend lunch / afternoon-tea service, 11:30–15:00 | Turnover and waitlist behaviour when the room is full; how released tables are re-sold. |
| O3 — pilot service (acceptance, W13) | One real dinner service using DineReserve | Not part of this file's data; recorded in the pilot report. |

**Observer protocol.** Non-participant observation from a staff-approved position; staff consent
obtained in advance; count and timing only (no diner names or contact details); stopwatch for
"booking taken" duration and for "table re-sold after cancellation"; photographs of the floor plan
only if the restaurant agrees and no guest is identifiable. One observer, one A5 tally sheet per
30-minute block.

### 2.3 Desk research

Secondary sources are used only to frame the problem, never to replace field evidence: the
open-source TastyIgniter platform and its extension model (constraint: the deployment must stay on
that base), published descriptions of comparable restaurant-booking systems, and the public pricing
pages of commercial booking platforms for the commission / customer-ownership comparison. Exact
figures and citations are added to URS §1.6 when the desk research is complete.

### 2.4 Limitations

- Five restaurants is a qualitative convenience sample; results are **not** statistically
  representative of Macau restaurants and must not be reported as percentages of the market.
- Self-reported no-show and cancellation rates are recall-based; they are cross-checked against the
  paper book or POS counts during observation where the restaurant allows it.
- The partner restaurant is a single pilot tenant; multi-tenant behaviour is required by the data
  model but is not validated with live tenants in year one (URS §4).
- Observer presence can change behaviour (Hawthorne effect); the first 30 minutes of each session
  are treated as warm-up and not coded.

---

## 3. Participant profile (planned sample)

The five restaurants are selected by **type**, not by name. Names below are placeholders until the
team fills the record table in Section 6.

| ID | Restaurant type (planned) | Why included | Planned respondents | Focus |
|---|---|---|---|---|
| R1 | Neighbourhood cha-cha-teng / café | High phone volume, fast turnover, paper book | Owner + front desk | Phone overload, manual allocation, booking time |
| R2 | Family-style Chinese restaurant | Large tables, banquets, phone-heavy | Owner/manager | Capacity allocation, late cancellations, no-shows |
| R3 | Western / bistro | Online-savvy diners, evening-led | Manager + 1 regular diner | Booking channels, reminders, platform commission |
| R4 | Japanese / small izakaya-style | Small room, full-house periods | Owner + front desk | Waitlist, released-table resale, no-show policy |
| R5 | Hotel-independent / tourist-area restaurant | Mixed local + visitor demand | Manager | Reporting needs, channel mix, data ownership |

<!-- TODO(团队填写): 用真实抽样结果替换上表的"planned"行；确认五家餐厅的类型分布与 URS §1.7 的"五家本地餐厅"表述一致。 -->

**Role coverage checks:**

- [ ] At least 5 owner/manager interviews (one per restaurant or a delegate with reservation authority).
- [ ] At least 3 front-desk / host interviews (the person who actually operates the paper book).
- [ ] 2–3 regular-diner interviews (to test the diner side of REQ-1.1 / REQ-1.2, including phone booking habits).
- [ ] At least one restaurant that already uses a commercial booking platform (for the commission / lock-in comparison).

---

## 4. Interview guide (10 questions)

The guide is intentionally short: it follows a real service rather than a feature list, so the
respondent describes the workaround before hearing about the proposed solution. Each question is
tagged with the proposal problem it probes (P1–P6, defined in Section 5) and the requirement it can
inform.

| # | Question | Probes | Informs |
|---|---|---|---|
| 1 | Walk me through last Friday's dinner service: when did the first reservation arrive, who took it, where was it written, and how did you know which table to give? | P1, P2 | REQ-1.1, REQ-1.2, REQ-1.6 |
| 2 | When the phone is busy or nobody can pick up during peak, what happens to the caller? How many calls do you think you miss on a busy night? | P1 | REQ-1.1, REQ-1.2 |
| 3 | How do you record a reservation today, and what happens if two staff members promise the same table? Show me the last time that happened. | P2 | REQ-1.1, REQ-1.2 |
| 4 | How do you decide which table to assign to a party — seats, area, rotation, regulars, something else? What makes it slow? | P2 | REQ-1.1, REQ-1.6 |
| 5 | How often do parties simply not turn up? What do you do when they don't — hold the table, release it, call them? | P3 | REQ-1.4, REQ-1.6, REQ-1.8 |
| 6 | Do you send any confirmation or reminder today (phone, SMS, WhatsApp, platform)? What works and what doesn't? | P3 | REQ-1.4 |
| 7 | When someone cancels late, how quickly can you re-sell the table? Do you keep a waitlist, and how is it ordered? | P4 | REQ-1.3, REQ-1.5 |
| 8 | What do you know about your business after a service — covers, no-show rate, busiest hours, channel mix? What would you like to know? | P5 | REQ-1.7 |
| 9 | Have you used or considered an online booking platform? What did it cost (commission/fee), who owned the customer data, and why did you stop or stay? | P6 | REQ-1.9 (self-hosted, data ownership) |
| 10 | What would make you trust a booking system enough to let diners book themselves — deposits, confirmation, cut-off rules, staff training, device/network constraints? | P6 / adoption | REQ-1.2, REQ-1.4, REQ-1.9 |

**Follow-up probes (use as needed, not as a checklist):** "When was the last time that happened?",
"What did you do next?", "How long did that take?", "Who else was involved?", "What would have
happened if the guest had booked online instead?", "May I see the book/roster?" (only if offered).

**Interviewer rules:** do not describe the proposed screens before question 10; do not suggest a
number (let the respondent estimate, then ask how they know); distinguish *stated* pain from
*observed* pain in the notes; write down verbatim quotes only when the respondent agrees.

---

## 5. Pain points → requirement map

The six problems come from the Task 1 proposal (also summarised in the repository README). The
mapping below is the hypothesis the interviews must confirm or reject; it is **not** a finding.
"Evidence (planned)" says which instrument will test it, not what the result already is.

| ID | Pain point (proposal) | Evidence to collect | Primary REQ | Secondary REQ | Success indicator (proposal target) |
|---|---|---|---|---|---|
| P1 | Phone overload during rush hours | Q1–Q2, O1 missed-call tally | REQ-1.1 (online availability), REQ-1.2 (self-service booking) | REQ-1.3 (self-service change/cancel) | Booking time 3–5 min → **< 60 s**; online share none → **≥ 70 %** |
| P2 | Manual table allocation errors / double promises | Q3–Q4, O1 double-booking incidents | REQ-1.1 (conflict-free availability), REQ-1.2 (atomic locking) | REQ-1.6 (floor plan / check-in) | Zero overlapping bookings on one table; no manual re-allocation at check-in |
| P3 | No-shows without reminders | Q5–Q6, O1 no-show count | REQ-1.4 (24 h / 2 h reminders), REQ-1.8 (risk flag) | REQ-1.5 (recover released table) | No-show rate **10–20 % → < 5 %** |
| P4 | Empty tables after late cancellations | Q7, O2 released-table resale time | REQ-1.3 (cancel releases table), REQ-1.5 (waitlist promotion) | REQ-1.6 (host release action) | Recovered covers 0 → **+10 % in peak** |
| P5 | No operating data for managers | Q8, O2 report requests | REQ-1.7 (occupancy / utilisation / no-show / channel mix) | REQ-1.8 (risk band vs outcome) | Four headline figures available for a chosen range and exportable |
| P6 | Commission and lock-in of commercial platforms | Q9–Q10, desk research | REQ-1.9 (self-hosted config, roles, policies, data ownership) | REQ-1.2, REQ-1.4 (own channel, own notifications) | Restaurant owns database and guest data; no per-cover commission |

**Requirement coverage check (all nine REQs must be anchored):**

| REQ | What it delivers | Primary pain point | URS section |
|---|---|---|---|
| REQ-1.1 | Search restaurant and table availability | P1, P2 | URS §6 (p.11) |
| REQ-1.2 | Create a booking | P1, P2 | URS §7 (p.13) |
| REQ-1.3 | Change or cancel a booking | P4 (also P1) | URS §8 (p.14) |
| REQ-1.4 | Automatic notification and reminder | P3 | URS §9 (p.16) |
| REQ-1.5 | Waitlist and automatic table release | P4 (also P3) | URS §10 (p.17) |
| REQ-1.6 | Floor-plan and table-status management | P2, P4 | URS §11 (p.19) |
| REQ-1.7 | Occupancy and utilisation reporting | P5 | URS §12 (p.20) |
| REQ-1.8 | No-show risk scoring | P3 | URS §13 (p.22) |
| REQ-1.9 | User, role and policy administration | P6 | URS §14 (p.23) |

> Note: the URS "Trace" column references **B1–B5**, but the Task 1 proposal defines its business
> objectives as **(a)–(e)**; B1–B5 are not defined in either document. This is recorded in
> [traceability § Known gaps in the Task 2 URS](05-traceability.md#5-known-gaps-in-the-task-2-urs).
> Do not invent a B1–B5 mapping here.

---

## 6. Interview record table (to be filled from transcripts)

One row per restaurant. Quotes must be **verbatim** (translated quotes can be kept in the original
language plus a team translation), and only quotes the respondent agreed to have used. Do not fill
a row from memory; use the transcript ID.

| ID | Restaurant pseudonym | Date / duration | Respondent role (years) | Key quote (verbatim, ≤ 30 words) | Pain point(s) | Transcript / audio ID |
|---|---|---|---|---|---|---|
| R1 | 待填 | 待填 | 待填 | 待填 | P? | 待填 |
| R2 | 待填 | 待填 | 待填 | 待填 | P? | 待填 |
| R3 | 待填 | 待填 | 待填 | 待填 | P? | 待填 |
| R4 | 待填 | 待填 | 待填 | 待填 | P? | 待填 |
| R5 | 待填 | 待填 | 待填 | 待填 | P? | 待填 |

<!-- TODO(团队填写): 逐行填入五家餐厅的化名、访谈日期与时长、受访者角色及从业年限、逐字关键引述（需经受访者同意）、对应痛点编号、转写/录音编号。每行必须对应一份真实转写；没有转写的行请删掉，不要留空造数。 -->

**Member-check log (optional):**

| ID | Summary sent back | Respondent correction received | Date |
|---|---|---|---|
| R1 | 待填 | 待填 | 待填 |
| R2 | 待填 | 待填 | 待填 |
| R3 | 待填 | 待填 | 待填 |
| R4 | 待填 | 待填 | 待填 |
| R5 | 待填 | 待填 | 待填 |

<!-- TODO(团队填写): 记录 member check 的发送日期与受访者更正内容；若未做 member check，请写明原因。 -->

---

## 7. On-site observation record (two sessions)

Counts only; no diner identity. The "per 30-minute block" tallies are summed for the session.

| Session | Date / service window | Phone calls in | Missed / engaged | Paper-book entries | Manual table moves | No-shows | Late cancels | Waitlist handling | Notes |
|---|---|---|---|---|---|---|---|---|---|
| O1 | 待填（周五晚餐 17:30–22:00） | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 |
| O2 | 待填（周末午餐/下午茶 11:30–15:00） | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 | 待填 |

<!-- TODO(团队填写): 填入两次到店观察的真实日期、时段、各项计数与现场笔记；附上观察清单照片（打印版）的存档位置。没有做的观察项请标注"未观察"，不要用估算数字代替。 -->

**Observation checklist (tick what was actually observed):**

- [ ] Phone rings during peak; who answers; calls left ringing / abandoned.
- [ ] Reservation written on paper / in a spreadsheet / in a platform / in WeChat.
- [ ] Table chosen by hand from the floor plan; allocation conflicts or last-second moves.
- [ ] Walk-ins seated or turned away while reserved tables sit empty.
- [ ] Party late: how long the table is held; who chases the guest; when it is released.
- [ ] Cancellation: how it arrives (phone/WeChat/platform) and how the table is re-sold.
- [ ] Waitlist exists? order rule? offer method (phone call, WhatsApp, in person)?
- [ ] End of service: any figures recorded (covers, no-shows, revenue) and by whom.
- [ ] Devices and network at the front desk (tablet/desktop/phone; Wi-Fi stability).
- [ ] Staff training effort for the current process (how a new host learns it).

---

## 8. Findings (to be completed after coding)

The findings section must be written only after the five transcripts are coded. Expected output:

1. **Confirmed / rejected assumptions** — one line per P1–P6 with the number of independent
   restaurants supporting it and the strongest verbatim quote ID.
2. **New pain points** not in the proposal, with the requirement change they would trigger
   (a new REQ or an amendment to an existing one).
3. **Baseline table** — measured baseline for each proposal success metric, with its source
   (interview estimate vs observation count) and confidence.
4. **Adoption constraints** — devices, connectivity, training time, deposit policy, privacy
   concerns; these feed REQ-1.9 policy defaults and the 5-minute training target.

<!-- TODO(团队填写): 访谈与观察完成后写结论：每个痛点的支持餐厅数、引述编号、与提案假设的差异、基线指标、以及需要新增/修改的 REQ。不要先写结论再补引述。 -->

---

## 9. Open items (team fill-in checklist)

- [ ] `<!-- TODO(团队填写): 五家餐厅访谈完成并上传转写 -->` — replace the five placeholder rows in §6.
- [ ] `<!-- TODO(团队填写): 两次到店观察完成 -->` — replace the two placeholder rows in §7.
- [ ] `<!-- TODO(团队填写): 知情同意书存档位置 -->` — store signed consent forms outside the repository; record only the folder/location here.
- [ ] `<!-- TODO(团队填写): 化名规则 -->` — confirm R1–R5 cannot be re-identified from the shared context.
- [ ] `<!-- TODO(团队填写): member check 结果 -->` — fill the member-check log in §6.
- [ ] `<!-- TODO(团队填写): 平台抽成/定价引用 -->` — add the public pricing pages used for P6 to URS §1.6 and cite them here; do not quote a commission percentage without a source.
- [ ] `<!-- TODO(团队填写): 基线指标 -->` — fill §8 item 3 from observation counts (no-show rate, online share, booking time).
- [ ] `<!-- TODO(团队填写): B1–B5 口径 -->` — resolve the URS B1–B5 vs proposal (a)–(e) mismatch with the URS author before the final report; do not guess the mapping.
- [ ] `<!-- TODO(团队填写): 隐私声明 -->` — confirm what is told to respondents about data retention and deletion.

---

## 10. Traceability of this document

- Pain points P1–P6 are derived from the Task 1 proposal / README problem statement, not invented in
  this file.
- REQ IDs in Section 5 are the URS §5 identifiers, copied verbatim; their implementation status is
  tracked in [docs/05-traceability.md](05-traceability.md).
- The system boundary and actor list assumed by the interview guide are drawn in
  [docs/diagrams/context.svg](diagrams/context.svg); the data entities discussed in questions 3–8
  are drawn in [docs/diagrams/er.svg](diagrams/er.svg).
- The service walkthrough in questions 1–7 follows the booking lifecycle in
  [docs/diagrams/booking-flow.svg](diagrams/booking-flow.svg).
- URS references: §1.7 (elicitation sources), §1.4 / §2.1 (context diagram, Figure 1), §4
  (assumptions and observation sessions), §5 (master list), §16 (NFRs).
