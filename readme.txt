=== Hillside Prays ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later

Prayer requests for Hillside: people ask for prayer, choose who can see it, and the church prays for them.

== Install ==
1. Plugins > Add New > Upload Plugin, choose hillside-prays.zip, then Activate.
2. Pages: a draft page called "Pray" now exists with the [hillside_prays] shortcode. Publish it.
   It isn't in the menu, so only people with the link can find it. That works for a pilot.
3. Hillside Prays > Settings: set the Care email (several emails with commas).
4. Users > Add New: give prayer team members the "Prayer Team" role and pastors/Care the "Hillside Care" role.
   Administrators and Editors already have access.

== Pilot switch ==
"Pray for others" for the whole church starts OFF. During the pilot the form only offers
"Pastors and Care" or "The prayer team". When leadership says yes, turn it on in
Hillside Prays > Settings > "Pray for others (whole church)". Nothing else changes.

== Approval (new in 0.3.0) ==
A request for the prayer team (or the whole church, when that's on) is NOT shown until someone
with Care access approves it. The "To approve" filter lists them; you can reword the text first,
as the Prayer lead does today. Private requests need no approval.

== The clock (new in 0.3.0) ==
Every request gets a "respond by" time: urgent 2 hours, prayer or pastor 24, practical help 48
(change these in Settings). Overdue requests show in red and sort to the top. "Mark followed up"
stops the clock; the Summary page counts how many were on time.

== Added by staff (new in 0.3.0) ==
A call, a Connect Card, an email or an in-person request: use "+ Add a request" on the Requests
page so it lands in the same log. The card shows how it came in.

== Care log ==
Every request is also the care log. On wp-admin > Hillside Prays, open "Assign to a ministry"
on a request to set the ministry, the owner (a Hillside Care user), a follow-up date and notes.
Filters: To follow up (new), In progress, Mine (assigned to you), Urgent, Reported, All, Archived.
Hillside Prays > Summary has the monthly numbers for reports.

== Simpler form (0.3.1) ==
"Who can see this?" only appears when someone chooses Prayer. A pastor conversation, practical
help and urgent requests are always private, so the form never asks.

== Who sees what ==
* Something urgent: always private. Care gets an email. It never shows publicly.
* Pastors and Care only: shown only in wp-admin > Hillside Prays.
* The prayer team: shown on the team page (/pray/?hp=team) after login, and in wp-admin.
* The whole church (first name, or no name): shown in "Pray for others" for 30 days (you can change this).
* Report: hides a request right away and emails Care. Use "Show again" under Reported to bring it back.

== Email ==
It uses the site's normal WordPress email (wp_mail). It doesn't need a new service.
If the site's other emails (contact forms, password resets) already arrive, these will too.
Once a day, at about 7-8 pm, each person who asked gets one email: "3 people prayed for you today".
On the days you pick in Settings (Mon and Thu to start), everyone with the Prayer Team role gets
one short note: how many new requests there are, with a link to the team page. The requests
themselves are never in the email, so nothing confidential can be forwarded.
WordPress runs these daily jobs when someone visits the site.

== Pages ==
/pray/              Start page
/pray/?hp=ask       The form
/pray/?hp=others    Pray for others
/pray/?hp=team      Prayer team (login)

== Removing ==
Deactivating stops the daily email. The requests stay in the database tables wp_hp_requests and wp_hp_prayers.
