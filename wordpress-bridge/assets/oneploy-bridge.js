(function () {
    'use strict';

    const config = window.OnePloyBridge || {};
    let observer;

    function text(tag, value, className) {
        const element = document.createElement(tag);
        element.textContent = value || '';
        if (className) element.className = className;
        return element;
    }

    function ensureHidden(form, name, value) {
        let field = form.querySelector('input[type="hidden"][name="' + name + '"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            form.appendChild(field);
        }
        field.value = value == null ? '' : String(value);
    }

    function clearDomainMetadata(form) {
        ensureHidden(form, 'oneploy_domain_available', '');
        ensureHidden(form, 'oneploy_domain_currency', '');
        ensureHidden(form, 'oneploy_domain_amount_minor', '');
    }

    function formattedQuote(quote) {
        if (typeof quote.formatted === 'string' && quote.formatted) return quote.formatted;
        const amount = Number(quote.amount_minor);
        if (!Number.isFinite(amount)) return '';
        const currency = quote.currency || config.defaultCurrency || 'USD';
        return currency + ' ' + (amount / 100).toFixed(2);
    }

    function renderDomainResult(form, results, domain, payload) {
        const availability = payload.availability || {};
        const quote = payload.quote || {};
        const available = availability.available === true;
        results.replaceChildren();
        results.appendChild(text('p', domain + ' ' + (available ? config.strings.available : config.strings.unavailable), available ? 'is-available' : 'is-unavailable'));
        const displayPrice = formattedQuote(quote);
        if (available && displayPrice) results.appendChild(text('p', displayPrice, 'oneploy-domain-price'));

        if (available && form.dataset.oneployCheckout === 'yes' && config.appUrl) {
            const link = text('a', config.strings.continue, 'oneploy-domain-checkout');
            const target = new URL('/domains', config.appUrl);
            target.searchParams.set('domain', domain);
            link.href = target.toString();
            results.appendChild(link);
        }

        const suggestionPayload = Array.isArray(payload.suggestions)
            ? payload.suggestions
            : (Array.isArray(payload.suggestions?.suggestions) ? payload.suggestions.suggestions : []);
        const suggestions = suggestionPayload.slice(0, 5);
        if (suggestions.length) {
            const list = document.createElement('ul');
            list.className = 'oneploy-domain-suggestions';
            suggestions.forEach((suggestion) => {
                const name = typeof suggestion === 'string' ? suggestion : suggestion?.domain || suggestion?.name;
                if (name) list.appendChild(text('li', name));
            });
            results.appendChild(list);
        }

        ensureHidden(form, 'oneploy_domain_available', available ? '1' : '0');
        ensureHidden(form, 'oneploy_domain_currency', quote.currency || form.dataset.oneployCurrency || config.defaultCurrency || 'USD');
        ensureHidden(form, 'oneploy_domain_amount_minor', quote.amount_minor ?? '');
        form.dispatchEvent(new CustomEvent('oneploy:domain-result', {
            bubbles: true,
            detail: { domain, available, availability, quote, suggestions },
        }));
    }

    async function searchDomain(form, input, results, state) {
        const domain = input.value.trim().toLowerCase();
        const requestSequence = ++state.requestSequence;
        state.controller?.abort();
        state.controller = null;
        clearDomainMetadata(form);

        if (!domain || !domain.includes('.')) {
            results.replaceChildren();
            return;
        }

        results.replaceChildren(text('p', config.strings.checking, 'is-checking'));
        const controller = new AbortController();
        state.controller = controller;

        try {
            const endpoint = new URL('/api/storefront/v1/domains/search', config.apiUrl);
            endpoint.searchParams.set('q', domain);
            endpoint.searchParams.set('currency', form.dataset.oneployCurrency || config.defaultCurrency || 'USD');
            const response = await fetch(endpoint.toString(), {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'omit',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Domain search failed.');
            const payload = await response.json();
            if (state.requestSequence !== requestSequence || input.value.trim().toLowerCase() !== domain) return;
            renderDomainResult(form, results, domain, payload);
        } catch (error) {
            if (error?.name === 'AbortError' || state.requestSequence !== requestSequence) return;
            clearDomainMetadata(form);
            results.replaceChildren(text('p', config.strings.error, 'oneploy-domain-error'));
            form.dispatchEvent(new CustomEvent('oneploy:domain-error', {
                bubbles: true,
                detail: { domain, message: config.strings.error },
            }));
        } finally {
            if (state.controller === controller) state.controller = null;
        }
    }

    function bindDomainForm(form, input, results, preventSubmit) {
        if (!form || !input || !results || form.dataset.oneployBound === 'true') return;
        form.dataset.oneployBound = 'true';
        const state = { requestSequence: 0, controller: null };
        let timer;

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            state.requestSequence++;
            state.controller?.abort();
            state.controller = null;
            clearDomainMetadata(form);
            results.replaceChildren();
            timer = window.setTimeout(() => searchDomain(form, input, results, state), 650);
        });
        input.addEventListener('blur', () => {
            window.clearTimeout(timer);
            searchDomain(form, input, results, state);
        });
        if (preventSubmit) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                window.clearTimeout(timer);
                searchDomain(form, input, results, state);
            });
        }
    }

    function initializeDomainForms() {
        document.querySelectorAll('[data-oneploy-domain-form]').forEach((form) => {
            bindDomainForm(form, form.querySelector('[data-oneploy-domain-input]'), form.querySelector('[data-oneploy-domain-results]'), form.dataset.oneployDomainWidget === 'true');
        });

        document.querySelectorAll('[data-oneploy-domain-binder]').forEach((binder) => {
            try {
                const form = document.querySelector(binder.dataset.formSelector);
                if (!form) return;
                form.dataset.oneployCurrency = binder.dataset.currency || config.defaultCurrency || 'USD';
                form.dataset.oneployCheckout = binder.dataset.checkout || 'no';
                const input = form.querySelector(binder.dataset.inputSelector || '[name="domain"]');
                const results = binder.dataset.resultsSelector ? document.querySelector(binder.dataset.resultsSelector) : form.querySelector('[data-oneploy-domain-results]');
                bindDomainForm(form, input, results, false);
            } catch (error) {
                // Invalid administrator selectors must never interrupt the original form.
            }
        });
    }

    function initialize() {
        initializeDomainForms();
        if (!observer && document.documentElement) {
            observer = new MutationObserver(initializeDomainForms);
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
