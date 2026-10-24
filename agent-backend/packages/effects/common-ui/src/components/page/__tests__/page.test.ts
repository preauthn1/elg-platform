import { mount } from '@vue/test-utils';

import { describe, expect, it } from 'vitest';

import { Page } from '..';

describe('page.vue', () => {
  it('renders title when passed', () => {
    const wrapper = mount(Page, {
      props: {
        title: 'Test Title',
      },
    });

    expect(wrapper.text()).toContain('Test Title');
  });

  it('renders description when passed', () => {
    const wrapper = mount(Page, {
      props: {
        description: 'Test Description',
      },
    });

    expect(wrapper.text()).toContain('Test Description');
  });

  it('renders default slot content', () => {
    const wrapper = mount(Page, {
      slots: {
        default: '<p>Default Slot Content</p>',
      },
    });

    expect(wrapper.html()).toContain('<p>Default Slot Content</p>');
  });

  it('renders footer slot when showFooter is true', () => {
    const wrapper = mount(Page, {
      props: {
        showFooter: true,
      },
      slots: {
        footer: '<p>Footer Slot Content</p>',
      },
    });

    expect(wrapper.html()).toContain('<p>Footer Slot Content</p>');
  });

  it('applies the custom contentClass', () => {
    const wrapper = mount(Page, {
      props: {
        contentClass: 'custom-class',
      },
    });

    const contentDiv = wrapper.find('.p-4');
    expect(contentDiv.classes()).toContain('custom-class');
  });

  it('does not render title slot if title prop is provided', () => {
    const wrapper = mount(Page, {
      props: {
        title: 'Test Title',
      },
      slots: {
        title: '<p>Title Slot Content</p>',
      },
    });

    expect(wrapper.text()).toContain('Title Slot Content');
    expect(wrapper.html()).not.toContain('Test Title');
  });

  it('does not render description slot if description prop is provided', () => {
    const wrapper = mount(Page, {
      props: {
        description: 'Test Description',
      },
      slots: {
        description: '<p>Description Slot Content</p>',
      },
    });

    expect(wrapper.text()).toContain('Description Slot Content');
    expect(wrapper.html()).not.toContain('Test Description');
  });

  it('reserves bottom space for the back-top button by default', () => {
    const wrapper = mount(Page);

    expect(wrapper.classes()).toContain('pb-20');
  });

  it('does not reserve bottom space when autoContentHeight is true', () => {
    const wrapper = mount(Page, {
      props: {
        autoContentHeight: true,
      },
    });

    expect(wrapper.classes()).not.toContain('pb-20');
  });

  it('does not reserve bottom space for a nested page', () => {
    const wrapper = mount(Page, {
      global: {
        components: { Page },
      },
      slots: {
        default: '<Page class="inner" />',
      },
    });

    expect(wrapper.classes()).toContain('pb-20');
    expect(wrapper.find('.inner').classes()).not.toContain('pb-20');
  });
});
