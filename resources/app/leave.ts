/**
 * The browser's own "leave this page?" dialog, raised while something on the
 * page would be interrupted by going elsewhere.
 *
 * Tracked tasks are carried along by every MigrateKit page, so moving from
 * one to the next is let through without asking; anything else — a reload,
 * closing the tab, leaving for another screen — asks first. Work that stays
 * behind when the page is gone is asked about on every move.
 *
 * Browsers only show the dialog for a page the user has already clicked in,
 * and show their own wording: the string assigned here is read by older ones
 * only.
 */

/** Says whether leaving right now would interrupt its work. Asked on every unload. */
type LeaveReason = () => boolean;

const reasons: LeaveReason[] = [];

/** Counted holds, for work that is left behind when the page is: `hold` when it starts, `release` when it ends. */
let holds = 0;

/** A link to another MigrateKit page was just clicked, and only carried work is running. */
let passing = false;

const PASSING_MS = 1500;

function busy(): boolean {
	return holds > 0 || reasons.some((reason) => reason());
}

function onBeforeUnload(event: BeforeUnloadEvent): void {
	if (!busy() || passing) {
		return;
	}

	event.preventDefault();
	event.returnValue = 'A MigrateKit task is still running. It waits here and carries on when you return to any MigrateKit page.';
}

const MIGRATEKIT_PAGE = /[?&]page=migratekit-[a-z]+/i;

function onDocumentClick(event: MouseEvent): void {
	// Work that would be left behind asks on every move, so a held page never passes.
	if (!busy() || holds > 0 || event.defaultPrevented || event.button !== 0) {
		return;
	}

	if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
		return;
	}

	const origin = event.target instanceof Element ? event.target : null;
	const link = origin === null ? null : origin.closest('a[href]');

	if (link === null) {
		return;
	}

	const href = link.getAttribute('href') || '';
	const target = link.getAttribute('target') || '';

	// Another place in this document, another tab or window, a download: none of them leaves the page.
	if (href.startsWith('#') || (target !== '' && target !== '_self') || link.hasAttribute('download')) {
		return;
	}

	let staysWithin = false;

	try {
		const url = new URL(href, window.location.href);

		staysWithin = url.origin === window.location.origin && MIGRATEKIT_PAGE.test(url.search);
	} catch {
		staysWithin = false;
	}

	if (!staysWithin) {
		return;
	}

	passing = true;
	window.setTimeout(() => {
		passing = false;
	}, PASSING_MS);
}

export function watchLeaving(reason: LeaveReason): void {
	reasons.push(reason);
}

export function holdBeforeUnload(): void {
	holds += 1;
}

export function releaseBeforeUnload(): void {
	holds = Math.max(0, holds - 1);
}

export function installLeaveGuard(): void {
	window.addEventListener('beforeunload', onBeforeUnload);
	document.addEventListener('click', onDocumentClick);
}
