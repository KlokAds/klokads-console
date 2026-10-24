// Phone: everything the app does, seen and controlled here — every message it caught and what
// became of it, which senders and wallet apps it listens to, and its settings.

import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'lock.dart';
import 'main.dart';

// ---------------------------------------------------------------------------
// Senders, wallet apps and ignore words: one list on the server, shared with the admin panel.
// The phone keeps a copy (for the native code, and to filter while offline).
// ---------------------------------------------------------------------------

class Sender {
  Sender(this.name, this.type, this.on);

  final String name;
  final String type; // sms | app
  bool on;

  Map<String, dynamic> toJson() => {'name': name, 'type': type, 'on': on};
}

class Rules {
  static List<Sender> senders = [];
  static List<String> ignore = [];
  static bool whitelist = true;

  static Future<Uri?> _url() async {
    final url = (await SharedPreferences.getInstance()).getString('config.url');
    return url == null ? null : Uri.parse(url);
  }

  static void _read(Map<String, dynamic> data) {
    senders = ((data['senders'] as List?) ?? []).map((s) => Sender('${s['name']}', '${s['type'] ?? 'sms'}', s['on'] != false)).toList();
    ignore = ((data['ignore'] as List?) ?? []).map((w) => '$w').toList();
    whitelist = data['whitelist'] != false;
  }

  /// What the native code reads: switched-on SMS senders, wallet apps, ignore words.
  static Future<void> _keep() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('inbox.senders', senders.where((s) => s.on && s.type == 'sms').map((s) => s.name).join('\n'));
    await prefs.setString('wallet.apps', senders.where((s) => s.on && s.type == 'app').map((s) => s.name.toLowerCase().replaceAll(' ', '')).join(','));
    await prefs.setString('inbox.ignore', ignore.join('\n'));
    await prefs.setBool('inbox.whitelist', whitelist);
    await prefs.setString('rules.cache', jsonEncode({'senders': senders.map((s) => s.toJson()).toList(), 'ignore': ignore, 'whitelist': whitelist}));
  }

  /// From the server (or the last copy when offline). @return false when offline.
  static Future<bool> sync() async {
    final prefs = await SharedPreferences.getInstance();
    final cached = prefs.getString('rules.cache');
    if (cached != null && senders.isEmpty) {
      _read(jsonDecode(cached) as Map<String, dynamic>);
    }
    final url = await _url();
    if (url == null) {
      return false;
    }
    try {
      final res = await http.get(url, headers: {'Accept': 'application/json'}).timeout(const Duration(seconds: 12));
      if (res.statusCode != 200) {
        return false;
      }
      _read(jsonDecode(res.body) as Map<String, dynamic>);
      await _keep();
      return true;
    } catch (_) {
      return false;
    }
  }

  /// Saves my change on the server, then keeps the copy. @return false when it could not be saved.
  static Future<bool> save() async {
    await _keep();
    final url = await _url();
    if (url == null) {
      return false;
    }
    try {
      final res = await http
          .put(url,
              headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
              body: jsonEncode({'senders': senders.map((s) => s.toJson()).toList(), 'ignore': ignore, 'whitelist': whitelist}))
          .timeout(const Duration(seconds: 12));
      if (res.statusCode == 200) {
        _read(jsonDecode(res.body) as Map<String, dynamic>);
        await _keep();
        return true;
      }
    } catch (_) {}
    return false;
  }
}

// ---------------------------------------------------------------------------

class PhonePage extends StatefulWidget {
  const PhonePage({super.key, required this.site, this.tab = 0, this.firstTime = false});

  final SitePageState site;
  final int tab;
  final bool firstTime;

  @override
  State<PhonePage> createState() => _PhonePageState();
}

class _PhonePageState extends State<PhonePage> {
  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 4,
      initialIndex: widget.tab,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('MH-Bot', style: TextStyle(fontWeight: FontWeight.w800)),
          bottom: const TabBar(isScrollable: true, tabAlignment: TabAlignment.start, tabs: [Tab(text: 'Follow-ups'), Tab(text: 'Messages'), Tab(text: 'Senders'), Tab(text: 'Settings')]),
        ),
        body: TabBarView(
          children: [
            const FollowUpsTab(),
            const MessagesTab(),
            const SendersTab(),
            SettingsTab(site: widget.site, firstTime: widget.firstTime),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Follow-ups: unseen messages and missed calls, what they're about, done / later / note.
// ---------------------------------------------------------------------------

class FollowUpsTab extends StatefulWidget {
  const FollowUpsTab({super.key});

  @override
  State<FollowUpsTab> createState() => _FollowUpsTabState();
}

class _FollowUpsTabState extends State<FollowUpsTab> {
  List<Map<String, dynamic>> open = [];
  List<Map<String, dynamic>> closed = [];
  Map<String, dynamic> sources = {};
  bool showClosed = false;
  bool loading = true;
  String? error;

  static const icons = {'whatsapp': '🟢', 'whatsapp_business': '🏢', 'messenger': '🔵', 'imo': '🟦', 'call': '📞'};

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<String?> base() async => (await SharedPreferences.getInstance()).getString('followups.url');

  Future<void> load() async {
    final url = await base();
    if (url == null) {
      setState(() {
        loading = false;
        error = 'আগে যুক্ত হতে হবে — personal side খুলুন।';
      });
      return;
    }
    try {
      final res = await http.get(Uri.parse(url), headers: {'Accept': 'application/json'}).timeout(const Duration(seconds: 12));
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        open = (data['open'] as List).cast<Map<String, dynamic>>();
        closed = (data['closed'] as List).cast<Map<String, dynamic>>();
        sources = data['sources'] as Map<String, dynamic>;
        loading = false;
        error = null;
      });
    } catch (_) {
      if (mounted) {
        setState(() {
          loading = false;
          error = 'নেট নেই — পরে আবার টানুন।';
        });
      }
    }
  }

  Future<void> act(Map<String, dynamic> f, String action, [Map<String, dynamic>? body]) async {
    final url = await base();
    if (url == null) return;
    try {
      await http.post(Uri.parse('$url/${f['id']}/$action'), headers: {'Accept': 'application/json', 'Content-Type': 'application/json'}, body: jsonEncode(body ?? {}));
      await load();
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('নেট নেই — হলো না।')));
    }
  }

  Future<void> note(Map<String, dynamic> f) async {
    final controller = TextEditingController(text: f['note'] as String? ?? '');
    final text = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('${f['person']} — কী কাজ?'),
        content: TextField(controller: controller, autofocus: true, maxLines: 3, decoration: const InputDecoration(hintText: 'যেমন invoice-এর ব্যাপারে')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('বাদ')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('রাখুন')),
        ],
      ),
    );
    if (text != null) await act(f, 'note', {'note': text});
  }

  String ago(String iso) {
    final m = DateTime.now().difference(DateTime.parse(iso)).inMinutes;
    if (m < 60) return '${m < 1 ? 1 : m} মি আগে';
    if (m < 1440) return '${(m / 60).round()} ঘ আগে';
    return '${(m / 1440).round()} দিন আগে';
  }

  @override
  Widget build(BuildContext context) {
    final list = showClosed ? closed : open;
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.only(bottom: 32),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 6),
            child: SegmentedButton<bool>(
              segments: [ButtonSegment(value: false, label: Text('বাকি (${open.length})')), const ButtonSegment(value: true, label: Text('হয়ে গেছে'))],
              selected: {showClosed},
              onSelectionChanged: (v) => setState(() => showClosed = v.first),
            ),
          ),
          if (loading) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
          if (error != null) Padding(padding: const EdgeInsets.all(32), child: Text(error!, textAlign: TextAlign.center)),
          if (!loading && error == null && list.isEmpty)
            Padding(
              padding: const EdgeInsets.all(40),
              child: Text(showClosed ? 'এখনো কিছু নেই।' : '✅ কিছু বাকি নেই। না দেখা WhatsApp/Messenger/IMO message আর missed call এখানে আসবে।', textAlign: TextAlign.center, style: const TextStyle(color: Colors.grey)),
            ),
          for (final f in list)
            Card(
              margin: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
              elevation: 0,
              color: Theme.of(context).colorScheme.surfaceContainerLow,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 12, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Text(icons[f['source']] ?? '💬', style: const TextStyle(fontSize: 20)),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('${f['person']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5)),
                          Text(
                            '${f['kind'] == 'missed_call' ? 'Missed call' : 'Unseen'} · ${sources[f['source']] ?? f['source']}${(f['count'] as num) > 1 ? ' · ${f['count']}×' : ''}',
                            style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: f['kind'] == 'missed_call' ? const Color(0xFFB45309) : teal),
                          ),
                        ]),
                      ),
                      Text(ago(f['first_at'] as String), style: const TextStyle(fontSize: 12, color: Colors.grey)),
                    ]),
                    if (f['preview'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text('“${f['preview']}”', maxLines: 3, overflow: TextOverflow.ellipsis)),
                    if (f['context'] != null)
                      Container(
                        margin: const EdgeInsets.only(top: 8),
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                        decoration: BoxDecoration(color: teal.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(12)),
                        child: Text('${f['context']}', style: const TextStyle(fontSize: 12.5)),
                      ),
                    if (f['note'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('📝 ${f['note']}', style: const TextStyle(fontSize: 12.5))),
                    const SizedBox(height: 4),
                    Wrap(spacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      if (!showClosed) ...[
                        FilledButton.tonal(onPressed: () => act(f, 'done'), child: const Text('Done')),
                        TextButton(onPressed: () => act(f, 'later', {'minutes': 60}), child: const Text('১ ঘণ্টা পরে')),
                        TextButton(onPressed: () => act(f, 'later', {'minutes': 1440}), child: const Text('কাল')),
                      ] else
                        TextButton(onPressed: () => act(f, 'later', {'minutes': 60}), child: const Text('আবার খুলুন')),
                      IconButton(tooltip: 'Note', onPressed: () => note(f), icon: const Icon(Icons.edit_note)),
                    ]),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Messages: every SMS / wallet notification caught, its state, and what the server made of it.
// ---------------------------------------------------------------------------

class MessagesTab extends StatefulWidget {
  const MessagesTab({super.key});

  @override
  State<MessagesTab> createState() => _MessagesTabState();
}

class _MessagesTabState extends State<MessagesTab> {
  List<Map<String, dynamic>> items = [];
  String filter = 'all';
  Timer? timer;

  static const filters = {'all': 'All', 'pending': 'Waiting', 'sent': 'Sent', 'failed': 'Failed', 'skipped': 'Skipped'};

  @override
  void initState() {
    super.initState();
    load();
    timer = Timer.periodic(const Duration(seconds: 4), (_) => load());
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  Future<void> load() async {
    final raw = await nativeChannel.invokeMethod<String>('history') ?? '[]';
    if (mounted) {
      setState(() => items = (jsonDecode(raw) as List).cast<Map<String, dynamic>>());
    }
  }

  int count(String state) => items.where((i) => i['state'] == state).length;

  /// What the server did with it, in words.
  ({String text, Color color}) outcome(Map<String, dynamic> m) {
    final account = (m['account'] as String?)?.isNotEmpty == true ? m['account'] as String : null;
    return switch (m['state']) {
      'pending' => (text: 'Waiting for internet', color: const Color(0xFFB45309)),
      'failed' => (text: '${m['error'] ?? 'Failed'}', color: const Color(0xFFE11D48)),
      'skipped' => (text: '${m['note'] ?? 'Not a transaction'}', color: Colors.grey),
      _ => switch (m['result']) {
          'added' => (text: account != null ? 'Added to $account${(m['note'] as String?)?.isNotEmpty == true ? ' · ${m['note']}' : ''}' : 'Added', color: const Color(0xFF047857)),
          'matched' => (text: '${m['note'] ?? 'Invoice paid'}', color: const Color(0xFF047857)),
          'review' => (text: 'Needs a look: ${m['note'] ?? 'which account?'}', color: const Color(0xFFB45309)),
          'ignored' => (text: '${m['note'] ?? 'Already recorded'}', color: Colors.grey),
          'skipped' => (text: 'Not a transaction', color: Colors.grey),
          _ => (text: 'Sent', color: const Color(0xFF047857)),
        },
    };
  }

  static const stateLook = {
    'pending': ('Waiting', Color(0xFFFEF3C7), Color(0xFFB45309)),
    'sent': ('Synced', Color(0xFFD1FAE5), Color(0xFF047857)),
    'failed': ('Failed', Color(0xFFFFE4E6), Color(0xFFE11D48)),
    'skipped': ('Skipped', Color(0xFFF1F5F9), Color(0xFF64748B)),
  };

  String day(int at) {
    final d = DateTime.fromMillisecondsSinceEpoch(at);
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final that = DateTime(d.year, d.month, d.day);
    if (that == today) return 'Today';
    if (that == today.subtract(const Duration(days: 1))) return 'Yesterday';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${d.day} ${months[d.month - 1]} ${d.year}';
  }

  String time(int at) {
    final d = DateTime.fromMillisecondsSinceEpoch(at);
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    return '$h:${d.minute.toString().padLeft(2, '0')} ${d.hour < 12 ? 'am' : 'pm'}';
  }

  Future<void> importRecent() async {
    final days = await showDialog<int>(
      context: context,
      builder: (context) => SimpleDialog(
        title: const Text('Phone-এ থাকা পুরোনো SMS পাঠাবো?'),
        children: [
          for (final d in [3, 7, 30])
            SimpleDialogOption(onPressed: () => Navigator.pop(context, d), child: Padding(padding: const EdgeInsets.all(4), child: Text('শেষ $d দিনের'))),
        ],
      ),
    );
    if (days == null) return;
    if (!await Permission.sms.isGranted && !(await Permission.sms.request()).isGranted) return;
    final added = await nativeChannel.invokeMethod<int>('importSms', {'days': days}) ?? 0;
    await load();
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(added < 0 ? 'SMS পড়া গেল না — অনুমতি দেখুন।' : added == 0 ? 'নতুন কিছু নেই — সব আগেই আছে।' : '$added টা SMS পাঠানো হচ্ছে। একই লেনদেন দুবার হিসাব হয় না।'),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final shown = filter == 'all' ? items : items.where((i) => i['state'] == filter).toList();
    final rows = <Widget>[];
    String? lastDay;
    for (final m in shown) {
      final at = (m['at'] as num).toInt();
      final d = day(at);
      if (d != lastDay) {
        rows.add(Padding(padding: const EdgeInsets.fromLTRB(20, 16, 20, 6), child: Text(d, style: const TextStyle(fontWeight: FontWeight.w700, color: Colors.grey))));
        lastDay = d;
      }
      final look = stateLook[m['state']] ?? stateLook['sent']!;
      final result = outcome(m);
      rows.add(Card(
        margin: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
        elevation: 0,
        color: Theme.of(context).colorScheme.surfaceContainerLow,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Icon(m['source'] == 'app' ? Icons.notifications_outlined : Icons.sms_outlined, size: 16, color: Colors.grey),
                const SizedBox(width: 6),
                Expanded(child: Text('${m['from']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5))),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(color: look.$2, borderRadius: BorderRadius.circular(20)),
                  child: Text(look.$1, style: TextStyle(color: look.$3, fontSize: 12, fontWeight: FontWeight.w700)),
                ),
              ]),
              const SizedBox(height: 6),
              Text('${m['text']}', maxLines: 3, overflow: TextOverflow.ellipsis, style: const TextStyle(height: 1.35)),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: Text(result.text, style: TextStyle(color: result.color, fontSize: 12.5, fontWeight: FontWeight.w600))),
                Text(time(at), style: const TextStyle(color: Colors.grey, fontSize: 12)),
                if (m['state'] == 'failed' || m['state'] == 'pending') ...[
                  const SizedBox(width: 8),
                  TextButton.icon(
                    style: TextButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: const Color(0xFFB45309)),
                    onPressed: () async {
                      await nativeChannel.invokeMethod('retry', {'id': m['id']});
                      await load();
                    },
                    icon: const Icon(Icons.refresh, size: 16),
                    label: const Text('Retry'),
                  ),
                ],
              ]),
            ],
          ),
        ),
      ));
    }

    return RefreshIndicator(
      onRefresh: () async {
        await nativeChannel.invokeMethod('retry', {'id': null});
        await nativeChannel.invokeMethod('poll');
        await load();
      },
      child: ListView(
        padding: const EdgeInsets.only(bottom: 32),
        children: [
          // Totals
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 4),
            child: Row(children: [
              for (final s in [('sent', 'Synced'), ('pending', 'Waiting'), ('failed', 'Failed')])
                Expanded(
                  child: Card(
                    elevation: 0,
                    color: stateLook[s.$1]!.$2,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      child: Column(children: [
                        Text('${count(s.$1)}', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: stateLook[s.$1]!.$3)),
                        Text(s.$2, style: TextStyle(fontSize: 12, color: stateLook[s.$1]!.$3)),
                      ]),
                    ),
                  ),
                ),
            ]),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            child: Row(children: [
              for (final f in filters.entries)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(label: Text(f.value), selected: filter == f.key, onSelected: (_) => setState(() => filter = f.key)),
                ),
              ActionChip(avatar: const Icon(Icons.download_outlined, size: 18), label: const Text('পুরোনো SMS'), onPressed: importRecent),
            ]),
          ),
          if (shown.isEmpty)
            const Padding(
              padding: EdgeInsets.all(40),
              child: Text('এখনো কিছু নেই। Bank বা bKash-এর SMS এলে এখানে দেখাবে — কোনটা গেছে, কোথায় যোগ হলো।', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey)),
            ),
          ...rows,
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Senders: bank / wallet SMS senders and wallet apps, each on or off. Same list as the panel.
// ---------------------------------------------------------------------------

class SendersTab extends StatefulWidget {
  const SendersTab({super.key});

  @override
  State<SendersTab> createState() => _SendersTabState();
}

class _SendersTabState extends State<SendersTab> {
  String type = 'sms';
  bool offline = false;
  bool saving = false;

  @override
  void initState() {
    super.initState();
    Rules.sync().then((ok) {
      if (mounted) setState(() => offline = !ok);
    });
  }

  Future<void> save() async {
    setState(() => saving = true);
    final ok = await Rules.save();
    if (!mounted) return;
    setState(() {
      saving = false;
      offline = !ok;
    });
    if (!ok) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Phone-এ রাখা হলো; নেট এলে আবার Save করুন।')));
    }
  }

  Future<void> add() async {
    final controller = TextEditingController();
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(type == 'sms' ? 'নতুন SMS sender' : 'নতুন wallet app'),
        content: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(hintText: type == 'sms' ? 'SMS-এ যেভাবে লেখা, যেমন SCB' : 'App-এর নাম, যেমন Wise'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('বাদ')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('যোগ করুন')),
        ],
      ),
    );
    if (name == null || name.isEmpty || Rules.senders.any((s) => s.name.toLowerCase() == name.toLowerCase())) return;
    setState(() => Rules.senders.add(Sender(name, type, true)));
    await save();
  }

  @override
  Widget build(BuildContext context) {
    final shown = Rules.senders.where((s) => s.type == type).toList();
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(onPressed: add, icon: const Icon(Icons.add), label: const Text('Add')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 96),
        children: [
          if (offline)
            const ListTile(leading: Icon(Icons.cloud_off, color: Color(0xFFB45309)), title: Text('নেট নেই — phone-এ রাখা তালিকা দেখাচ্ছে')),
          SwitchListTile(
            value: Rules.whitelist,
            title: const Text('শুধু চালু করা sender', style: TextStyle(fontWeight: FontWeight.w600)),
            subtitle: const Text('বন্ধ করলে: যেকোনো bank/wallet নামের SMS যাবে (মানুষের নম্বর কখনো না)'),
            onChanged: (v) {
              setState(() => Rules.whitelist = v);
              save();
            },
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'sms', label: Text('SMS senders'), icon: Icon(Icons.sms_outlined)),
                ButtonSegment(value: 'app', label: Text('Wallet apps'), icon: Icon(Icons.apps)),
              ],
              selected: {type},
              onSelectionChanged: (v) => setState(() => type = v.first),
            ),
          ),
          if (type == 'app')
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text('এদের notification থেকে টাকা আসা-যাওয়া নেওয়া হয় — Settings-এ "Notification access" চালু থাকতে হবে।', style: TextStyle(color: Colors.grey, fontSize: 12.5)),
            ),
          for (final s in shown)
            Dismissible(
              key: ValueKey('${s.type}:${s.name}'),
              direction: DismissDirection.endToStart,
              background: Container(color: const Color(0xFFFFE4E6), alignment: Alignment.centerRight, padding: const EdgeInsets.only(right: 24), child: const Icon(Icons.delete_outline, color: Color(0xFFE11D48))),
              onDismissed: (_) {
                setState(() => Rules.senders.remove(s));
                save();
              },
              child: SwitchListTile(
                value: s.on,
                title: Text(s.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(s.on ? 'চালু — হিসাবে যাচ্ছে' : 'বন্ধ', style: TextStyle(color: s.on ? const Color(0xFF047857) : Colors.grey)),
                onChanged: saving
                    ? null
                    : (v) {
                        setState(() => s.on = v);
                        save();
                      },
              ),
            ),
          if (shown.isEmpty) const Padding(padding: EdgeInsets.all(32), child: Text('এখানে কিছু নেই — Add চাপুন।', textAlign: TextAlign.center)),
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 12, 20, 0),
            child: Text('বাম দিকে টানলে মুছে যাবে। এই তালিকা admin panel-এর Auto entries › Setup-এও একই।', style: TextStyle(color: Colors.grey, fontSize: 12)),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Settings: permissions, lock, ignore words, updates.
// ---------------------------------------------------------------------------

class SettingsTab extends StatefulWidget {
  const SettingsTab({super.key, required this.site, this.firstTime = false});

  final SitePageState site;
  final bool firstTime;

  @override
  State<SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<SettingsTab> with WidgetsBindingObserver {
  bool paired = false;
  bool sms = false;
  bool notify = false;
  bool wallet = false;
  bool battery = false;
  static const followUpApps = {'whatsapp': 'WhatsApp', 'whatsapp_business': 'WhatsApp Business', 'messenger': 'Messenger', 'imo': 'IMO', 'call': 'Phone calls'};
  Set<String> watching = {};
  bool callLog = false;
  String lockMode = 'off';
  int lockAfter = 1;
  String theme = 'system';
  DateTime? lastSent;
  String version = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // Back from the phone's own settings: show what changed.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) load();
  }

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.reload();
    final info = await PackageInfo.fromPlatform();
    final last = prefs.getInt('inbox.last_ok');
    final values = (
      sms: await Permission.sms.isGranted,
      notify: await Permission.notification.isGranted,
      wallet: await nativeChannel.invokeMethod<bool>('walletAccess') ?? false,
      battery: await nativeChannel.invokeMethod<bool>('batteryFree') ?? false,
      callLog: await Permission.phone.isGranted,
      lockMode: await Lock.mode(),
      lockAfter: await Lock.after(),
      theme: await widget.site.theme(),
    );
    if (!mounted) return;
    setState(() {
      paired = prefs.getString('feed.url') != null && prefs.getString('inbox.error') == null;
      sms = values.sms;
      notify = values.notify;
      wallet = values.wallet;
      battery = values.battery;
      callLog = values.callLog;
      watching = (prefs.getString('followup.apps') ?? followUpApps.keys.join(',')).split(',').where((s) => s.isNotEmpty).toSet();
      lockMode = values.lockMode;
      lockAfter = values.lockAfter;
      theme = values.theme;
      lastSent = last != null ? DateTime.fromMillisecondsSinceEpoch(last) : null;
      version = '${info.version} (${info.buildNumber})';
    });
  }

  Widget row({required IconData icon, required String title, required String text, required bool ok, String? action, VoidCallback? onTap}) {
    return ListTile(
      leading: CircleAvatar(
        backgroundColor: ok ? const Color(0xFFD1FAE5) : const Color(0xFFFEF3C7),
        child: Icon(ok ? Icons.check_rounded : icon, color: ok ? const Color(0xFF047857) : const Color(0xFFB45309)),
      ),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(text),
      trailing: ok || action == null ? null : FilledButton.tonal(onPressed: onTap, child: Text(action)),
    );
  }

  /// Which apps follow-ups watch (the native side reads this list).
  Future<void> setWatching(String key, bool on) async {
    if (key == 'call' && on && !(await Permission.phone.request()).isGranted) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Call log-এর অনুমতি ছাড়া missed call দেখা যাবে না।')));
      return;
    }
    final next = {...watching};
    on ? next.add(key) : next.remove(key);
    await (await SharedPreferences.getInstance()).setString('followup.apps', next.join(','));
    setState(() => watching = next);
    if (key == 'call' && on) await nativeChannel.invokeMethod('poll');
  }

  /// Changing the lock asks for the current one first.
  Future<void> changeLock(String next) async {
    if (next == lockMode || !await confirmIdentity(context, 'Lock বদলাতে')) return;
    var ok = true;
    if (next == 'bio') ok = await Lock.finger('আঙুলের ছাপ চালু করতে');
    if (next == 'pin' && mounted) ok = await newPin(context);
    if (!ok) return;
    await Lock.setMode(next);
    setState(() => lockMode = next);
  }

  /// From the server, through the feed, to this phone: proves the whole chain.
  Future<void> testNotification() async {
    final prefs = await SharedPreferences.getInstance();
    final config = prefs.getString('config.url');
    final messenger = ScaffoldMessenger.of(context);
    if (config == null) {
      messenger.showSnackBar(const SnackBar(content: Text('আগে যুক্ত হতে হবে — personal side খুলুন।')));
      return;
    }
    if (!await Permission.notification.isGranted && !(await Permission.notification.request()).isGranted) {
      messenger.showSnackBar(const SnackBar(content: Text('Notification-এর অনুমতি নেই — উপরে দিন।')));
      return;
    }
    try {
      final res = await http.post(Uri.parse(config.replaceFirst('/app/config/', '/app/test/')), headers: {'Accept': 'application/json'}).timeout(const Duration(seconds: 12));
      if (res.statusCode != 200) throw Exception('${res.statusCode}');
      await nativeChannel.invokeMethod('poll');
      messenger.showSnackBar(const SnackBar(content: Text('পাঠানো হলো — কয়েক সেকেন্ডে notification আসবে।')));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text('Server-এ পৌঁছানো গেল না ($e)।')));
    }
  }

  Future<void> addWord() async {
    final controller = TextEditingController();
    final word = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Ignore word'),
        content: TextField(controller: controller, autofocus: true, decoration: const InputDecoration(hintText: 'যেমন offer')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('বাদ')),
          FilledButton(onPressed: () => Navigator.pop(context, controller.text.trim().toLowerCase()), child: const Text('যোগ করুন')),
        ],
      ),
    );
    if (word == null || word.isEmpty || Rules.ignore.contains(word)) return;
    setState(() => Rules.ignore.add(word));
    await Rules.save();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.only(bottom: 32),
      children: [
        if (widget.firstTime)
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 16, 20, 4),
            child: Text('নিচের সবগুলো চালু থাকলে bank SMS আর wallet-এর টাকা নিজে থেকে হিসাবে উঠবে, আর প্রতিটা credit/debit আর reminder phone-এ আসবে।'),
          ),
        const _Heading('Security'),
        const Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, 10),
          child: Text('Lock চালু থাকলে app-এর ভেতরে website-এর PIN আর চাইবে না — এই lock-ই যথেষ্ট। Lock বন্ধ থাকলে website-এর PIN চাইবে।', style: TextStyle(color: Colors.grey, fontSize: 12.5)),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
          child: SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'off', label: Text('বন্ধ'), icon: Icon(Icons.lock_open)),
              ButtonSegment(value: 'bio', label: Text('আঙুলের ছাপ'), icon: Icon(Icons.fingerprint)),
              ButtonSegment(value: 'pin', label: Text('PIN'), icon: Icon(Icons.pin_outlined)),
            ],
            selected: {lockMode},
            onSelectionChanged: (v) => changeLock(v.first),
          ),
        ),
        if (lockMode != 'off') ...[
          const Padding(padding: EdgeInsets.fromLTRB(20, 12, 20, 6), child: Text('বাইরে গেলে lock হবে', style: TextStyle(fontWeight: FontWeight.w600))),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<int>(
              segments: [for (final a in Lock.afters.entries) ButtonSegment(value: a.key, label: Text(a.value))],
              selected: {lockAfter},
              onSelectionChanged: (v) async {
                await Lock.setAfter(v.first);
                setState(() => lockAfter = v.first);
              },
            ),
          ),
        ],
        if (lockMode == 'pin')
          ListTile(
            leading: const Icon(Icons.password),
            title: const Text('PIN বদলান'),
            onTap: () async {
              if (await confirmIdentity(context, 'PIN বদলাতে') && context.mounted && await newPin(context) && context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('নতুন PIN রাখা হলো।')));
              }
            },
          ),
        const _Heading('Theme'),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
          child: SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'system', label: Text('Phone-এর মতো'), icon: Icon(Icons.brightness_auto)),
              ButtonSegment(value: 'light', label: Text('Light'), icon: Icon(Icons.light_mode_outlined)),
              ButtonSegment(value: 'dark', label: Text('Dark'), icon: Icon(Icons.dark_mode_outlined)),
            ],
            selected: {theme},
            onSelectionChanged: (v) async {
              await widget.site.setTheme(v.first);
              setState(() => theme = v.first);
            },
          ),
        ),
        const _Heading('Follow-ups'),
        const Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, 4),
          child: Text('না দেখা message আর ধরা হয়নি এমন call — ১ ঘণ্টা পর মনে করিয়ে দেবে, কার আর কী কাজের জন্য সহ।', style: TextStyle(color: Colors.grey, fontSize: 12.5)),
        ),
        for (final app in followUpApps.entries)
          SwitchListTile(
            dense: true,
            value: watching.contains(app.key),
            title: Text(app.value, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: app.key == 'call' && !callLog ? const Text('Call log-এর অনুমতি লাগবে') : null,
            onChanged: (v) => setWatching(app.key, v),
          ),
        if (!wallet)
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 0, 20, 4),
            child: Text('WhatsApp/Messenger/IMO-র জন্য নিচে "Notification access" চালু করুন।', style: TextStyle(color: Color(0xFFB45309), fontSize: 12.5)),
          ),
        const _Heading('Notifications'),
        ListTile(
          leading: const Icon(Icons.notifications_active_outlined),
          title: const Text('Test notification পাঠান'),
          subtitle: const Text('Server থেকে phone পর্যন্ত সব ঠিক আছে কিনা — কয়েক সেকেন্ডে আসবে'),
          onTap: testNotification,
        ),
        const _Heading('Permissions'),
        row(icon: Icons.link, title: 'আমার হিসাবের সাথে যুক্ত', text: paired ? 'Messages যাচ্ছে, notification আসছে' : 'Personal side খুলুন (PIN দিয়ে) — নিজে থেকে যুক্ত হবে', ok: paired),
        row(
          icon: Icons.sms_outlined,
          title: 'SMS পড়া',
          text: 'শুধু চালু করা sender-এর SMS যায়',
          ok: sms,
          action: 'অনুমতি',
          onTap: () async {
            if ((await Permission.sms.request()).isPermanentlyDenied) await openAppSettings();
            await load();
          },
        ),
        row(
          icon: Icons.account_balance_wallet_outlined,
          title: 'Wallet app notification',
          text: 'nSave, Rizon, Elevate Pay… — "Notification access"-এ MH-Bot চালু করুন',
          ok: wallet,
          action: 'চালু করুন',
          onTap: () => nativeChannel.invokeMethod('openWalletAccess'),
        ),
        row(
          icon: Icons.notifications_outlined,
          title: 'Notification দেখানো',
          text: 'প্রতিটা credit, debit আর reminder',
          ok: notify,
          action: 'অনুমতি',
          onTap: () async {
            await Permission.notification.request();
            await load();
          },
        ),
        row(
          icon: Icons.battery_saver_outlined,
          title: 'Battery saver থেকে বাদ',
          text: 'না হলে কিছু phone background-এ app থামিয়ে দেয়',
          ok: battery,
          action: 'বাদ দিন',
          onTap: () => nativeChannel.invokeMethod('openBattery'),
        ),
        const _Heading('Ignore words'),
        const Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
          child: Text('এই শব্দ থাকলে message কখনো যায় না (TrxID থাকলে ছাড়া)। Admin panel-এও একই।', style: TextStyle(color: Colors.grey, fontSize: 12.5)),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final w in Rules.ignore)
              InputChip(
                label: Text(w),
                onDeleted: () async {
                  setState(() => Rules.ignore.remove(w));
                  await Rules.save();
                },
              ),
            ActionChip(avatar: const Icon(Icons.add, size: 18), label: const Text('Add'), onPressed: addWord),
          ]),
        ),
        const _Heading('App'),
        ListTile(
          leading: const Icon(Icons.outbox_outlined),
          title: const Text('শেষ পাঠানো'),
          subtitle: Text(lastSent != null ? lastSent!.toLocal().toString().substring(0, 16) : 'এখনো কিছু পাঠানো হয়নি'),
        ),
        ListTile(
          leading: const Icon(Icons.system_update_outlined),
          title: const Text('নতুন version আছে কিনা দেখুন'),
          subtitle: Text('এখন: $version'),
          onTap: () => widget.site.checkUpdate(now: true),
        ),
        if (paired)
          ListTile(
            leading: const Icon(Icons.restart_alt),
            title: const Text('আবার যুক্ত করুন'),
            subtitle: const Text('Link বদলালে বা কাজ না করলে'),
            onTap: () async {
              final prefs = await SharedPreferences.getInstance();
              await prefs.remove('config.url');
              if (context.mounted) Navigator.of(context).pop();
              widget.site.open('/personal');
            },
          ),
      ],
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 6),
        child: Text(text.toUpperCase(), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, letterSpacing: 1.1, color: Colors.grey)),
      );
}
