// Klok — my own app: the console (personal side first, business one tap away) inside, plus
// what only a phone can do: read bank SMS and wallet-app notifications so money is booked by
// itself, and show every credit, debit and reminder as a phone notification.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:local_auth/local_auth.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

const site = 'https://console.klokads.com';
const ink = Color(0xFF0B1020);
const teal = Color(0xFF0D9488);
const nativeChannel = MethodChannel('klok/native');

int appBuild = 0;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  appBuild = int.tryParse((await PackageInfo.fromPlatform()).buildNumber) ?? 0;
  runApp(const KlokApp());
}

class KlokApp extends StatelessWidget {
  const KlokApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Klok',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: teal, useMaterial3: true),
      home: const LockGate(child: SitePage()),
    );
  }
}

// ---------------------------------------------------------------------------
// The lock: my fingerprint (or the phone's own PIN behind it) every time the app opens, and
// again after a minute away. Only I open this app.
// ---------------------------------------------------------------------------

class LockGate extends StatefulWidget {
  const LockGate({super.key, required this.child});

  final Widget child;

  @override
  State<LockGate> createState() => _LockGateState();
}

class _LockGateState extends State<LockGate> with WidgetsBindingObserver {
  bool locked = true;
  bool asking = false;
  DateTime? leftAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unlock();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // The fingerprint prompt itself sends the app to the background for a moment.
    if (asking) {
      return;
    }
    if (state == AppLifecycleState.paused) {
      leftAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed && leftAt != null) {
      final away = DateTime.now().difference(leftAt!);
      leftAt = null;
      if (away >= const Duration(minutes: 1)) {
        setState(() => locked = true);
        unlock();
      }
      unawaited(nativeChannel.invokeMethod('poll').catchError((_) => null));
    }
  }

  Future<void> unlock() async {
    if (asking) {
      return;
    }
    asking = true;
    var ok = false;
    final auth = LocalAuthentication();
    try {
      ok = !await auth.isDeviceSupported() ||
          await auth.authenticate(localizedReason: 'Klok খুলতে', options: const AuthenticationOptions(stickyAuth: true));
    } on PlatformException catch (e) {
      // A phone with no screen lock at all has nothing to ask.
      ok = e.code == 'NotAvailable' || e.code == 'PasscodeNotSet' || e.code == 'NotEnrolled';
    } finally {
      asking = false;
    }
    if (mounted) {
      setState(() => locked = !ok);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        widget.child,
        if (locked)
          Positioned.fill(
            child: Material(
              color: ink,
              child: SafeArea(
                child: Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.fingerprint, size: 76, color: Color(0xFF5EEAD4)),
                      const SizedBox(height: 18),
                      const Text('Klok', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: Colors.white)),
                      const SizedBox(height: 6),
                      const Text('শুধু আপনার জন্য', style: TextStyle(color: Colors.white60)),
                      const SizedBox(height: 28),
                      FilledButton.icon(
                        style: FilledButton.styleFrom(backgroundColor: teal, padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 14)),
                        onPressed: unlock,
                        icon: const Icon(Icons.lock_open),
                        label: const Text('খুলুন'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// The site inside. Once I'm signed in and the personal side is unlocked, the app pairs
// itself: it gets the private URLs for forwarding and for the notification feed.
// ---------------------------------------------------------------------------

class SitePage extends StatefulWidget {
  const SitePage({super.key});

  @override
  State<SitePage> createState() => _SitePageState();
}

class _SitePageState extends State<SitePage> {
  InAppWebViewController? web;
  PullToRefreshController? refresh;
  double progress = 0;
  bool failed = false;
  bool pairing = false;
  DateTime? checkedUpdate;
  String? first;

  @override
  void initState() {
    super.initState();
    refresh = PullToRefreshController(settings: PullToRefreshSettings(color: teal), onRefresh: () async => web?.reload());
    nativeChannel.setMethodCallHandler((call) async {
      if (call.method == 'open' && call.arguments is String) {
        open(call.arguments as String);
      }
    });
  }

  void open(String url) {
    final target = url.startsWith('http') ? url : '$site${url.startsWith('/') ? '' : '/'}$url';
    web?.loadUrl(urlRequest: URLRequest(url: WebUri(target)));
  }

  /// A JSON request as me (the page's own cookies and CSRF token).
  Future<({int status, dynamic body})?> request(String method, String path) async {
    final result = await web?.callAsyncJavaScript(
      functionBody: '''
        const m = document.cookie.match(/(?:^|;\\s*)XSRF-TOKEN=([^;]+)/);
        const r = await fetch(path, {
          method,
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
        });
        let data = null;
        try { data = await r.json(); } catch (e) {}
        return { status: r.status, body: data };
      ''',
      arguments: {'path': path, 'method': method},
    );
    final value = result?.value;
    return value is Map ? (status: (value['status'] as num?)?.toInt() ?? 0, body: value['body']) : null;
  }

  /// On the unlocked personal side, once: the URLs for forwarding and notifications.
  Future<void> pair(WebUri? url) async {
    final prefs = await SharedPreferences.getInstance();
    final paired = prefs.getString('feed.url') != null && prefs.getString('inbox.error') == null;
    if (paired || pairing || url == null || url.host != Uri.parse(site).host) {
      return;
    }
    if (!url.path.startsWith('/personal') || url.path.startsWith('/personal/unlock')) {
      return;
    }
    pairing = true;
    try {
      final result = await request('POST', '/personal/app/pair');
      final body = result?.body;
      if (result?.status != 200 || body is! Map) {
        return;
      }
      await prefs.setString('inbox.url', body['inbox'] as String);
      await prefs.setString('feed.url', body['feed'] as String);
      await prefs.setInt('feed.last', (body['last_id'] as num?)?.toInt() ?? 0);
      await prefs.remove('inbox.error');
      await nativeChannel.invokeMethod('paired');
      await Permission.notification.request();
      await Permission.sms.request();
      if (mounted) {
        await openSettings(firstTime: true);
      }
    } finally {
      pairing = false;
    }
  }

  /// Is there a newer APK? It is downloaded and installed by the app itself.
  Future<void> checkUpdate({bool now = false}) async {
    if (!now && checkedUpdate != null && DateTime.now().difference(checkedUpdate!) < const Duration(hours: 6)) {
      return;
    }
    final result = await request('GET', '/app/latest');
    final body = result?.body;
    if (body is! Map) {
      return;
    }
    checkedUpdate = DateTime.now();
    final version = (body['version'] as num?)?.toInt() ?? 0;
    final url = body['url'] as String?;
    if (version <= appBuild || url == null || !mounted) {
      if (now && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('এটাই সবচেয়ে নতুন version।')));
      }
      return;
    }
    final allowed = await nativeChannel.invokeMethod<bool>('updateAllowed') ?? false;
    if (!allowed) {
      if (!mounted) {
        return;
      }
      final go = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('নতুন version এসেছে'),
          content: Text('Version ${body['name'] ?? version}। একবার অনুমতি দিন — এরপর app নিজেই নতুনটা বসাবে।'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('পরে')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('অনুমতি দিন')),
          ],
        ),
      );
      if (go == true) {
        await nativeChannel.invokeMethod('askUpdateAllowed');
        checkedUpdate = null;
      }
      return;
    }
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Version ${body['name'] ?? version} নামানো হচ্ছে…')));
    }
    await nativeChannel.invokeMethod('install', {'url': url});
  }

  Future<void> openSettings({bool firstTime = false}) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SettingsPage(site: this, firstTime: firstTime)));
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) {
          return;
        }
        if (await web?.canGoBack() ?? false) {
          await web?.goBack();
        } else {
          await SystemNavigator.pop();
        }
      },
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: Stack(
            children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: WebUri('$site/personal')),
                pullToRefreshController: refresh,
                initialSettings: InAppWebViewSettings(
                  applicationNameForUserAgent: 'KlokApp/$appBuild',
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  databaseEnabled: true,
                  thirdPartyCookiesEnabled: false,
                  allowFileAccess: false,
                  useShouldOverrideUrlLoading: true,
                  useOnDownloadStart: true,
                  supportZoom: false,
                ),
                onWebViewCreated: (controller) async {
                  web = controller;
                  controller.addJavaScriptHandler(handlerName: 'appSettings', callback: (_) => openSettings());
                  // Opened by tapping a notification: go straight to its page.
                  first = await nativeChannel.invokeMethod<String>('launchUrl');
                  if (first != null && first!.isNotEmpty) {
                    open(first!);
                  }
                },
                shouldOverrideUrlLoading: (controller, action) async {
                  final uri = action.request.url;
                  if (uri == null) {
                    return NavigationActionPolicy.ALLOW;
                  }
                  // Phone calls, WhatsApp and other sites open in their own apps.
                  if (!['http', 'https'].contains(uri.scheme) || uri.host != Uri.parse(site).host) {
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                    return NavigationActionPolicy.CANCEL;
                  }
                  return NavigationActionPolicy.ALLOW;
                },
                onDownloadStartRequest: (controller, request) async {
                  await launchUrl(request.url, mode: LaunchMode.externalApplication);
                },
                onProgressChanged: (controller, value) {
                  setState(() => progress = value / 100);
                  if (value == 100) {
                    refresh?.endRefreshing();
                  }
                },
                onLoadStop: (controller, url) async {
                  refresh?.endRefreshing();
                  if (failed) {
                    setState(() => failed = false);
                  }
                  await pair(url);
                  unawaited(checkUpdate());
                  unawaited(nativeChannel.invokeMethod('poll').catchError((_) => null));
                },
                onReceivedError: (controller, request, error) {
                  refresh?.endRefreshing();
                  if (request.isForMainFrame ?? true) {
                    setState(() => failed = true);
                  }
                },
              ),
              if (progress < 1) LinearProgressIndicator(value: progress, minHeight: 2, color: teal, backgroundColor: Colors.transparent),
              if (failed)
                Positioned.fill(
                  child: ColoredBox(
                    color: Colors.white,
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.all(28),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.wifi_off_rounded, size: 56, color: teal),
                            const SizedBox(height: 14),
                            const Text('নেট নেই', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                            const SizedBox(height: 8),
                            const Text('আগে খোলা পাতা আর offline entry চলবে। Bank SMS phone-এ জমা থাকবে, নেট এলে নিজে যাবে।', textAlign: TextAlign.center),
                            const SizedBox(height: 18),
                            FilledButton.icon(
                              style: FilledButton.styleFrom(backgroundColor: teal),
                              onPressed: () {
                                setState(() => failed = false);
                                web?.reload();
                              },
                              icon: const Icon(Icons.refresh),
                              label: const Text('আবার চেষ্টা'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// The app's own settings: what the phone is allowed to do, and its status.
// ---------------------------------------------------------------------------

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key, required this.site, this.firstTime = false});

  final _SitePageState site;
  final bool firstTime;

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> with WidgetsBindingObserver {
  bool paired = false;
  bool sms = false;
  bool notify = false;
  bool wallet = false;
  bool battery = false;
  int pending = 0;
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

  // Back from the phone's settings: show what changed.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      load();
    }
  }

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.reload();
    final info = await PackageInfo.fromPlatform();
    final last = prefs.getInt('inbox.last_ok');
    final values = (
      paired: prefs.getString('feed.url') != null && prefs.getString('inbox.error') == null,
      sms: await Permission.sms.isGranted,
      notify: await Permission.notification.isGranted,
      wallet: await nativeChannel.invokeMethod<bool>('walletAccess') ?? false,
      battery: await nativeChannel.invokeMethod<bool>('batteryFree') ?? false,
      pending: await nativeChannel.invokeMethod<int>('pending') ?? 0,
    );
    if (!mounted) {
      return;
    }
    setState(() {
      paired = values.paired;
      sms = values.sms;
      notify = values.notify;
      wallet = values.wallet;
      battery = values.battery;
      pending = values.pending;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.firstTime ? 'Klok সেট আপ' : 'Klok app')),
      body: ListView(
        padding: const EdgeInsets.symmetric(vertical: 8),
        children: [
          if (widget.firstTime)
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 8, 20, 12),
              child: Text('নিচের সবগুলো চালু থাকলে bank SMS আর wallet-এর টাকা নিজে থেকে হিসাবে উঠবে, আর প্রতিটা credit/debit আর reminder phone-এ আসবে।'),
            ),
          row(
            icon: Icons.link,
            title: 'আমার হিসাবের সাথে যুক্ত',
            text: paired ? 'এই phone থেকে SMS আর notification যাচ্ছে' : 'Personal side খুলুন (PIN দিয়ে) — নিজে থেকে যুক্ত হবে',
            ok: paired,
          ),
          row(
            icon: Icons.sms_outlined,
            title: 'Bank আর bKash/Nagad SMS',
            text: 'শুধু bank/wallet-এর SMS যায়, মানুষের নম্বরের SMS কখনো না',
            ok: sms,
            action: 'অনুমতি',
            onTap: () async {
              final status = await Permission.sms.request();
              if (status.isPermanentlyDenied) {
                await openAppSettings();
              }
              await load();
            },
          ),
          row(
            icon: Icons.account_balance_wallet_outlined,
            title: 'Wallet app (nSave, Rizon, Elevate Pay…)',
            text: 'এদের notification থেকে টাকা আসা-যাওয়া — "Notification access"-এ Klok চালু করুন',
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
            text: 'না হলে কিছু phone background-এ app বন্ধ করে দেয়',
            ok: battery,
            action: 'বাদ দিন',
            onTap: () => nativeChannel.invokeMethod('openBattery'),
          ),
          const Divider(height: 32),
          ListTile(
            leading: const Icon(Icons.outbox_outlined),
            title: Text(pending == 0 ? 'সব পাঠানো হয়েছে' : '$pending টা পাঠানোর অপেক্ষায়'),
            subtitle: Text(lastSent != null ? 'শেষ পাঠানো: ${lastSent!.toLocal().toString().substring(0, 16)}' : 'এখনো কিছু পাঠানো হয়নি'),
            trailing: IconButton(
              icon: const Icon(Icons.sync),
              onPressed: () async {
                await nativeChannel.invokeMethod('poll');
                await Future<void>.delayed(const Duration(seconds: 2));
                await load();
              },
            ),
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
                await prefs.remove('feed.url');
                await load();
                if (mounted) {
                  Navigator.of(context).pop();
                }
                widget.site.open('/personal');
              },
            ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
